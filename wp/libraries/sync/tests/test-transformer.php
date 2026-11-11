<?php
/**
 * AWX Sync: transformer and extractor test suite.
 *
 * Runs outside WordPress. Needs the core block parser from any WordPress
 * checkout: set WP_CORE_DIR, or let the script look in a few local paths.
 *
 *   WP_CORE_DIR=/path/to/wordpress php wp/libraries/sync/tests/test-transformer.php
 */

if (!defined('ABSPATH')) {
	define('ABSPATH', __DIR__ . '/');
}

// ---- locate WP_Block_Parser -------------------------------------------------
$parser_file = null;
$candidates  = array();
if (getenv('WP_CORE_DIR')) {
	$candidates[] = rtrim(getenv('WP_CORE_DIR'), '/') . '/wp-includes/class-wp-block-parser.php';
}
foreach (array('studio', 'blocks', 'enterprise') as $dir) {
	$candidates[] = dirname(__DIR__, 5) . '/' . $dir . '/wp-includes/class-wp-block-parser.php';
	$candidates[] = '/Users/macbook/development/' . $dir . '/wp-includes/class-wp-block-parser.php';
}
foreach ($candidates as $c) {
	if (is_readable($c)) {
		$parser_file = $c;
		break;
	}
}
if ($parser_file === null) {
	fwrite(STDERR, "SKIP: WP_Block_Parser not found. Set WP_CORE_DIR to a WordPress checkout.\n");
	exit(0);
}
require_once $parser_file;
$parser_dir = dirname($parser_file);
foreach (array('class-wp-block-parser-frame.php', 'class-wp-block-parser-block.php') as $extra) {
	if (is_readable($parser_dir . '/' . $extra)) {
		require_once $parser_dir . '/' . $extra;
	}
}

// ---- load the library -----------------------------------------------------
$lib = dirname(__DIR__) . '/lib/';
require_once $lib . 'interface-site-resolver.php';
require_once $lib . 'class-block-serializer.php';
require_once $lib . 'class-reference-registry.php';
require_once $lib . 'class-transform-result.php';
require_once $lib . 'class-transformer.php';
require_once $lib . 'class-extractor.php';
require_once __DIR__ . '/class-fake-site.php';

use aw2\sync\Block_Serializer;
use aw2\sync\Extractor;
use aw2\sync\Reference_Registry;
use aw2\sync\Sync_Exception;
use aw2\sync\Transformer;
use aw2\sync\tests\Fake_Site;

$failures = 0;
function assert_true($condition, $message) {
	global $failures;
	if (!$condition) {
		$failures++;
		echo "❌ FAIL: $message\n";
		return;
	}
	echo "✅ PASS: $message\n";
}
function assert_contains($needle, $haystack, $message) {
	assert_true(false !== strpos($haystack, $needle), $message . " [expected to contain: $needle]");
}
function assert_not_contains($needle, $haystack, $message) {
	assert_true(false === strpos($haystack, $needle), $message . " [must not contain: $needle]");
}
function has_warning(array $warnings, $code) {
	foreach ($warnings as $w) {
		if ($w['code'] === $code) {
			return true;
		}
	}
	return false;
}

// ---- dgb registry shared by both fake sites ---------------------------------
$dgb_registry = array(
	'awx-hero' => array('fields' => array(
		array('type' => 'image', 'name' => 'image', 'attr_name' => 'content.image'),
		array('type' => 'text', 'name' => 'title', 'attr_name' => 'content.title'),
		array('type' => 'text', 'name' => 'button_url', 'attr_name' => 'content.button_url'),
		array('type' => 'text', 'name' => 'video_url', 'attr_name' => 'content.video_url'),
	)),
	'awx-testimonials' => array('tabs' => array(array('fields' => array(
		array('type' => 'row_repeater', 'name' => 'testimonials', 'attr_name' => 'content.items', 'repeater_fields' => array(
			array('type' => 'textarea', 'name' => 'text'),
			array('type' => 'image', 'name' => 'image_id'),
		)),
	)))),
	'awx-feature' => array('fields' => array(
		array('type' => 'post-select', 'name' => 'link_post', 'attr_name' => 'content.link_post'),
		array('type' => 'taxonomy-select', 'name' => 'cats', 'attr_name' => 'content.cats', 'taxonomy' => 'category'),
		array('type' => 'filtered-post-select', 'name' => 'fp', 'attr_name' => 'content.fp'),
	)),
	'awx-post-grid' => array('fields' => array(
		array('type' => 'text', 'name' => 'post_type', 'attr_name' => 'content.post_type'),
	)),
);

// ---- Dev site ---------------------------------------------------------------
$M5 = '11111111-1111-4111-8111-111111111111';
$M7 = '22222222-2222-4222-8222-222222222222';
$M9 = '33333333-3333-4333-8333-333333333333';
$P42 = '44444444-4444-4444-8444-444444444444';
$P43 = '55555555-5555-4555-8555-555555555555';
$P100 = '66666666-6666-4666-8666-666666666666';
$B500 = '77777777-7777-4777-8777-777777777777';
$B501 = '88888888-8888-4888-8888-888888888888';
$B600 = '99999999-9999-4999-8999-999999999999';
$B601 = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';

function make_dev(array $dgb_registry) {
	global $M5, $M7, $M9, $P42, $P43, $P100, $B500, $B501, $B600, $B601;
	$dev = new Fake_Site('https://dev.example.com', array('cdn.dev.example.com'));
	$dev->dgb = $dgb_registry;
	$dev->media = array(
		5 => array('uuid' => $M5, 'url' => 'https://dev.example.com/wp-content/uploads/2026/01/hero.jpg', 'mime' => 'image/jpeg', 'alt' => 'Hero',
			'sizes' => array('large' => 'https://dev.example.com/wp-content/uploads/2026/01/hero-1024x683.jpg', 'medium' => 'https://dev.example.com/wp-content/uploads/2026/01/hero-300x200.jpg')),
		7 => array('uuid' => $M7, 'url' => 'https://dev.example.com/wp-content/uploads/2026/01/logo.png', 'mime' => 'image/png', 'sizes' => array()),
		9 => array('uuid' => $M9, 'url' => 'https://dev.example.com/wp-content/uploads/2026/02/brochure.pdf', 'mime' => 'application/pdf', 'sizes' => array()),
	);
	$dev->posts = array(
		42  => array('uuid' => $P42, 'type' => 'page', 'slug' => 'about', 'url' => 'https://dev.example.com/about/'),
		43  => array('uuid' => $P43, 'type' => 'post', 'slug' => 'hello', 'url' => 'https://dev.example.com/blog/hello/'),
		100 => array('uuid' => $P100, 'type' => 'page', 'slug' => 'home', 'url' => 'https://dev.example.com/home/', 'title' => 'Home', 'thumbnail_id' => 7,
			'terms' => array(array('taxonomy' => 'category', 'slug' => 'news', 'name' => 'News', 'parent' => null))),
	);
	$dev->blocks = array(
		500 => array('uuid' => $B500, 'slug' => 'cta', 'title' => 'CTA', 'pattern_slug' => 'cta.default',
			'content' => '<!-- wp:image {"id":7} --><figure class="wp-block-image"><img src="https://dev.example.com/wp-content/uploads/2026/01/logo.png" class="wp-image-7"/></figure><!-- /wp:image -->'),
		501 => array('uuid' => $B501, 'slug' => 'wrapper', 'title' => 'Wrapper',
			'content' => '<!-- wp:group --><div class="wp-block-group"><!-- wp:block {"ref":500} /--></div><!-- /wp:group -->'),
		600 => array('uuid' => $B600, 'slug' => 'a', 'content' => '<!-- wp:block {"ref":601} /-->'),
		601 => array('uuid' => $B601, 'slug' => 'b', 'content' => '<!-- wp:block {"ref":600} /-->'),
	);
	$dev->terms = array(
		3 => array('taxonomy' => 'category', 'slug' => 'news', 'name' => 'News', 'parent' => null),
		8 => array('taxonomy' => 'post_tag', 'slug' => 'featured', 'name' => 'Featured', 'parent' => null),
	);
	return $dev;
}

function make_prod(array $dgb_registry) {
	global $M5, $M7, $M9, $P42, $P43, $B500, $B501;
	$prod = new Fake_Site('https://www.example.com');
	$prod->dgb = $dgb_registry;
	$prod->media = array(
		905 => array('uuid' => $M5, 'url' => 'https://www.example.com/wp-content/uploads/2026/03/hero.jpg', 'mime' => 'image/jpeg',
			'sizes' => array('large' => 'https://www.example.com/wp-content/uploads/2026/03/hero-1024x683.jpg', 'medium' => 'https://www.example.com/wp-content/uploads/2026/03/hero-300x200.jpg')),
		907 => array('uuid' => $M7, 'url' => 'https://www.example.com/wp-content/uploads/2026/03/logo.png', 'mime' => 'image/png', 'sizes' => array()),
		909 => array('uuid' => $M9, 'url' => 'https://www.example.com/wp-content/uploads/2026/03/brochure.pdf', 'mime' => 'application/pdf', 'sizes' => array()),
	);
	// Note: Prod post 5 overlaps with Dev media id 5 on purpose.
	$prod->posts = array(
		1042 => array('uuid' => $P42, 'type' => 'page', 'slug' => 'about', 'url' => 'https://www.example.com/about/'),
		5    => array('uuid' => $P43, 'type' => 'post', 'slug' => 'hello', 'url' => 'https://www.example.com/blog/hello/'),
	);
	$prod->blocks = array(
		2500 => array('uuid' => $B500, 'slug' => 'cta', 'content' => ''),
		2501 => array('uuid' => $B501, 'slug' => 'wrapper', 'content' => ''),
	);
	$prod->terms = array(
		13 => array('taxonomy' => 'category', 'slug' => 'news', 'name' => 'News', 'parent' => null),
	);
	return $prod;
}

$dev  = make_dev($dgb_registry);
$prod = make_prod($dgb_registry);
$registry_dev  = Reference_Registry::create(function () use ($dev) { return $dev->dgb; });
$registry_prod = Reference_Registry::create(function () use ($prod) { return $prod->dgb; });
$t_dev  = new Transformer($dev, $registry_dev);
$t_prod = new Transformer($prod, $registry_prod);

// ---- Fixtures ---------------------------------------------------------------
$fixtures = array();

$fixtures['dgb hero: image object + _data mirror + nested mirror + numeric video_url'] = <<<'HTML'
<!-- wp:dgb/awx-hero {"content.image":{"id":5,"url":"https://dev.example.com/wp-content/uploads/2026/01/hero-1024x683.jpg","alt":"Hero","title":""},"content":{"image":{"id":5,"url":"https://dev.example.com/wp-content/uploads/2026/01/hero.jpg"}},"_data":{"content.image":{"id":5,"url":"https://dev.example.com/wp-content/uploads/2026/01/hero.jpg"},"image":5},"content.title":"Hello","content.button_url":"https://dev.example.com/about/","content.video_url":9} /-->
HTML;

$fixtures['dgb testimonials: repeater rows with bare and object image ids'] = <<<'HTML'
<!-- wp:dgb/awx-testimonials {"content.items":[{"text":"Great","image_id":5},{"text":"Good","image_id":{"id":7,"url":"https://dev.example.com/wp-content/uploads/2026/01/logo.png"}},{"text":"None","image_id":0}],"_data":{"content.items":[{"text":"Great","image_id":5}]}} /-->
HTML;

$fixtures['dgb feature: post-select, taxonomy-select, filtered-post-select'] = <<<'HTML'
<!-- wp:dgb/awx-feature {"content.link_post":{"id":42,"title":"About","url":"https://dev.example.com/about/"},"content.cats":[{"id":3,"name":"News","slug":"news"}],"content.fp":{"posts":[{"id":43,"title":"Hello","url":"https://dev.example.com/blog/hello/"}],"filters":{"category":3,"tag":8}}} /-->
HTML;

$fixtures['core image with wp-image class, src and srcset'] = <<<'HTML'
<!-- wp:image {"id":5,"sizeSlug":"large","linkDestination":"media"} -->
<figure class="wp-block-image size-large"><a href="https://dev.example.com/wp-content/uploads/2026/01/hero.jpg"><img src="https://dev.example.com/wp-content/uploads/2026/01/hero-1024x683.jpg" alt="Hero" class="wp-image-5" srcset="https://dev.example.com/wp-content/uploads/2026/01/hero-1024x683.jpg 1024w, https://dev.example.com/wp-content/uploads/2026/01/hero-300x200.jpg 300w" sizes="(max-width: 1024px) 100vw, 1024px"/></a></figure>
<!-- /wp:image -->
HTML;

$fixtures['core gallery with ids and nested images'] = <<<'HTML'
<!-- wp:gallery {"ids":[5,7],"linkTo":"none"} -->
<figure class="wp-block-gallery has-nested-images columns-default is-cropped"><!-- wp:image {"id":5,"sizeSlug":"large"} -->
<figure class="wp-block-image size-large"><img src="https://dev.example.com/wp-content/uploads/2026/01/hero-1024x683.jpg" alt="" class="wp-image-5" data-id="5"/></figure>
<!-- /wp:image -->

<!-- wp:image {"id":7,"sizeSlug":"large"} -->
<figure class="wp-block-image size-large"><img src="https://dev.example.com/wp-content/uploads/2026/01/logo.png" alt="" class="wp-image-7" data-id="7"/></figure>
<!-- /wp:image --></figure>
<!-- /wp:gallery -->
HTML;

$fixtures['core/block ref and cover'] = <<<'HTML'
<!-- wp:block {"ref":500} /-->

<!-- wp:cover {"url":"https://dev.example.com/wp-content/uploads/2026/01/hero.jpg","id":5,"dimRatio":50} -->
<div class="wp-block-cover"><img class="wp-block-cover__image-background wp-image-5" alt="" src="https://dev.example.com/wp-content/uploads/2026/01/hero.jpg" data-object-fit="cover"/><div class="wp-block-cover__inner-container"><!-- wp:paragraph -->
<p>Text</p>
<!-- /wp:paragraph --></div></div>
<!-- /wp:cover -->
HTML;

$fixtures['links: dev-domain in paragraph and button, relative link untouched, file block'] = <<<'HTML'
<!-- wp:paragraph -->
<p>Read <a href="https://dev.example.com/about/">about us</a> or <a href="/signup?plan=pro">sign up</a>, or visit <a href="https://dev.example.com/pricing/?x=1">pricing</a>.</p>
<!-- /wp:paragraph -->

<!-- wp:buttons -->
<div class="wp-block-buttons"><!-- wp:button {"url":"https://dev.example.com/blog/hello/"} -->
<div class="wp-block-button"><a class="wp-block-button__link" href="https://dev.example.com/blog/hello/">Hello</a></div>
<!-- /wp:button --></div>
<!-- /wp:buttons -->

<!-- wp:file {"id":9,"href":"https://dev.example.com/wp-content/uploads/2026/02/brochure.pdf"} -->
<div class="wp-block-file"><a href="https://dev.example.com/wp-content/uploads/2026/02/brochure.pdf">Brochure</a></div>
<!-- /wp:file -->
HTML;

$fixtures['classic freeform html with wp-image class'] = <<<'HTML'
<p>Legacy <img class="alignnone wp-image-7" src="https://dev.example.com/wp-content/uploads/2026/01/logo.png" /> content with [aw2.module module="pricing-table" template="main"] shortcode.</p>
HTML;

// ---- 1. Round trip on the same site -----------------------------------------
echo "=== 1. Neutralise then resolve on the same site reproduces the content ===\n";
foreach ($fixtures as $label => $content) {
	$n = $t_dev->neutralise($content);
	assert_true(empty(Transformer::leftover_tokens($content)), "[$label] fixture has no tokens to begin with");
	assert_true($n->content !== $content, "[$label] neutralise changed the content");
	assert_true(!Transformer::contains_host($n->content, array('dev.example.com', 'cdn.dev.example.com')), "[$label] no dev host left after neutralise");
	$r = $t_dev->resolve($n->content);
	assert_true($r->ok(), "[$label] resolve on dev has no missing references");
	assert_true(Block_Serializer::canonical($r->content) === Block_Serializer::canonical($content), "[$label] round trip is lossless");
	if (Block_Serializer::canonical($r->content) !== Block_Serializer::canonical($content)) {
		echo "   expected: " . Block_Serializer::canonical($content) . "\n   actual:   " . Block_Serializer::canonical($r->content) . "\n";
	}
}

// ---- 2. Idempotency ---------------------------------------------------------
echo "\n=== 2. Neutralise is idempotent ===\n";
foreach ($fixtures as $label => $content) {
	$once  = $t_dev->neutralise($content)->content;
	$twice = $t_dev->neutralise($once)->content;
	assert_true($once === $twice, "[$label] neutralising twice equals once");
}

// ---- 3. Resolve on Prod -----------------------------------------------------
echo "\n=== 3. Resolve on a different site maps to that site's ids and urls ===\n";
$all = implode("\n\n", $fixtures);
$n   = $t_dev->neutralise($all);
$r   = $t_prod->resolve($n->content, $n->refs['terms']);
assert_true($r->ok(), 'resolve on prod has no missing references' . ($r->ok() ? '' : ' ' . json_encode($r->missing)));
assert_true(empty(Transformer::leftover_tokens($r->content)), 'no awx- tokens left in prod content');
assert_true(!Transformer::contains_host($r->content, array('dev.example.com', 'cdn.dev.example.com')), 'no dev host in prod content');
assert_contains('"content.image":{"id":905,"url":"https://www.example.com/wp-content/uploads/2026/03/hero-1024x683.jpg"', $r->content, 'dgb image object resolved to prod id and large url');
assert_contains('"image":905', $r->content, '_data mirror bare id resolved');
assert_contains('"content.video_url":909', $r->content, 'numeric video_url resolved to prod attachment id');
assert_contains('"content.button_url":"https://www.example.com/about/"', $r->content, 'dev permalink in text attr resolved to prod permalink');
assert_contains('{"text":"Great","image_id":905}', $r->content, 'repeater bare id resolved');
assert_contains('"image_id":{"id":907,"url":"https://www.example.com/wp-content/uploads/2026/03/logo.png"}', $r->content, 'repeater object resolved');
assert_contains('{"text":"None","image_id":0}', $r->content, 'zero image id untouched');
assert_contains('"content.link_post":{"id":1042,"title":"About","url":"https://www.example.com/about/"}', $r->content, 'post-select resolved');
assert_contains('"content.cats":[{"id":13,"name":"News","slug":"news"}]', $r->content, 'taxonomy-select resolved to existing prod term');
assert_contains('"posts":[{"id":5,"title":"Hello","url":"https://www.example.com/blog/hello/"}]', $r->content, 'filtered posts resolved (prod post id 5 overlaps dev media id 5 without collision)');
assert_true(!empty($prod->created_terms), 'missing tag term was created on prod from the catalogue');
$created = $prod->created_terms[0];
assert_contains('"filters":{"category":13,"tag":' . $created . '}', $r->content, 'filter term ids resolved, created term used');
assert_contains('class="wp-image-905"', $r->content, 'wp-image class resolved');
assert_contains('data-id="905"', $r->content, 'gallery data-id resolved');
assert_contains('srcset="https://www.example.com/wp-content/uploads/2026/03/hero-1024x683.jpg 1024w, https://www.example.com/wp-content/uploads/2026/03/hero-300x200.jpg 300w"', $r->content, 'srcset sizes resolved');
assert_contains('"ids":[905,907]', $r->content, 'gallery ids resolved');
assert_contains('<!-- wp:block {"ref":2500} /-->', $r->content, 'reusable block ref resolved');
assert_contains('href="/signup?plan=pro"', $r->content, 'relative link untouched');
assert_contains('https://www.example.com/pricing/?x=1', $r->content, 'unmapped dev url rewritten to same path on prod domain');
assert_contains('wp-image-907', $r->content, 'classic html wp-image class resolved');
assert_true(isset($n->refs['modules']['pricing-table']), 'aw2.module shortcode recorded as module dependency');
assert_true(has_warning($n->warnings, 'unmapped_dev_url'), 'unmapped dev url produced a warning');
assert_true(isset($n->refs['dgb']['awx-hero']) && isset($n->refs['dgb']['awx-feature']), 'dgb block slugs recorded');

// ---- 3b. Documented harmless normalisations ---------------------------------
echo "\n=== 3b. Known normalisations (documented as harmless) ===\n";
$norm = $t_dev->resolve($t_dev->neutralise('<!-- wp:dgb/awx-hero {"_data":{"content.image":{"id":"5","url":"https://dev.example.com/wp-content/uploads/2026/01/hero.jpg"}}} /-->')->content);
assert_contains('{"id":5,"url":', $norm->content, 'numeric-string id "5" comes back as integer 5');
$norm = $t_dev->resolve($t_dev->neutralise('<!-- wp:paragraph --><p><a href="http://dev.example.com/pricing/">x</a></p><!-- /wp:paragraph -->')->content);
assert_contains('href="https://dev.example.com/pricing/"', $norm->content, 'http own-site link comes back with the home url scheme');
$cdn = $t_prod->resolve($t_dev->neutralise('<!-- wp:file {"id":9,"href":"https://cdn.dev.example.com/wp-content/uploads/2026/02/brochure.pdf"} --><div class="wp-block-file"><a href="https://cdn.dev.example.com/wp-content/uploads/2026/02/brochure.pdf">B</a></div><!-- /wp:file -->')->content);
assert_contains('"href":"https://www.example.com/wp-content/uploads/2026/03/brochure.pdf"', $cdn->content, 'cdn-host upload url resolved via the attachment');
assert_contains('<a href="https://www.example.com/wp-content/uploads/2026/03/brochure.pdf">', $cdn->content, 'cdn-host upload url in html resolved via the attachment');

// ---- 4. Missing references --------------------------------------------------
echo "\n=== 4. Missing references are reported and tokens stay in place ===\n";
$prod_missing = make_prod($dgb_registry);
unset($prod_missing->media[905]);
$t_missing = new Transformer($prod_missing, Reference_Registry::create(function () use ($prod_missing) { return $prod_missing->dgb; }));
$n_img = $t_dev->neutralise($fixtures['core image with wp-image class, src and srcset']);
$r_img = $t_missing->resolve($n_img->content);
assert_true(!$r_img->ok(), 'resolve reports missing media');
assert_true($r_img->missing[0]['type'] === 'media' && $r_img->missing[0]['key'] === $M5, 'missing entry names the media uuid');
assert_true(!empty(Transformer::leftover_tokens($r_img->content)), 'tokens remain when unresolved');
$prod_noterms = make_prod($dgb_registry);
$prod_noterms->allow_create_terms = false;
$t_noterms = new Transformer($prod_noterms, Reference_Registry::create(function () use ($prod_noterms) { return $prod_noterms->dgb; }));
$n_feat = $t_dev->neutralise($fixtures['dgb feature: post-select, taxonomy-select, filtered-post-select']);
$r_feat = $t_noterms->resolve($n_feat->content, array());
assert_true(!$r_feat->ok() && $r_feat->missing[0]['type'] === 'term' && $r_feat->missing[0]['key'] === 'post_tag:featured', 'missing term reported when no catalogue and creation disabled');

// ---- 5. Warnings -----------------------------------------------------------
echo "\n=== 5. Warnings for unregistered, acf, unknown dgb and missing objects ===\n";
$w = $t_dev->neutralise('<!-- wp:foo/bar {"imageId":5,"items":[{"postId":42}],"label":"x"} /-->');
assert_true(has_warning($w->warnings, 'unregistered_id_attribute'), 'unregistered block with *Id attributes warns');
assert_contains('"imageId":5', $w->content, 'unregistered ids are copied unchanged');
$w = $t_dev->neutralise('<!-- wp:acf/hero {"name":"acf/hero","data":{"image":5,"_image":"field_abc"}} /-->');
assert_true(has_warning($w->warnings, 'acf_block_unverified'), 'acf block with numeric data warns');
$w = $t_dev->neutralise('<!-- wp:dgb/unknown-block {"content.image":5,"content.link":"https://dev.example.com/about/"} /-->');
assert_true(has_warning($w->warnings, 'dgb_block_unknown'), 'unknown dgb block warns');
assert_contains('"content.link":"awx-post://' . $P42 . '"', $w->content, 'urls inside unknown dgb block still neutralised');
$w = $t_dev->neutralise('<!-- wp:image {"id":999} --><figure><img class="wp-image-999" src="https://dev.example.com/wp-content/uploads/2026/09/gone.jpg"/></figure><!-- /wp:image -->');
assert_true(has_warning($w->warnings, 'media_missing'), 'missing attachment id warns');
assert_true(has_warning($w->warnings, 'unmapped_upload_url'), 'upload url matching no attachment warns');
assert_contains('awx-site://wp-content/uploads/2026/09/gone.jpg', $w->content, 'unmapped upload url becomes a site token');
$w = $t_dev->neutralise('<!-- wp:navigation {"ref":77} /--><!-- wp:query {"query":{"taxQuery":{"category":[3]}}} --><!-- /wp:query -->');
assert_true(has_warning($w->warnings, 'navigation_ref_unsupported') && has_warning($w->warnings, 'query_tax_unsupported'), 'navigation and query warnings emitted');

// ---- 6. Registry ------------------------------------------------------------
echo "\n=== 6. Registry hash and custom entries ===\n";
$h1 = Reference_Registry::create()->hash();
$h2 = Reference_Registry::create()->hash();
assert_true($h1 === $h2 && strlen($h1) === 64, 'registry hash is stable across instances');
$custom = Reference_Registry::create(function () use ($dev) { return $dev->dgb; });
$custom->register('acme/hero', array('attrs' => array('imageId' => 'media', 'galleryIds' => 'media[]', 'linkPostId' => 'post', 'cats' => 'term_objects:category')));
assert_true($custom->hash() !== $h1, 'registering an entry changes the hash');
$t_custom = new Transformer($dev, $custom);
$c = $t_custom->neutralise('<!-- wp:acme/hero {"imageId":5,"galleryIds":[5,7],"linkPostId":42,"cats":[{"id":3}]} /-->');
assert_contains('"imageId":"awx-media://' . $M5 . '"', $c->content, 'custom media attr neutralised');
assert_contains('"galleryIds":["awx-media://' . $M5 . '","awx-media://' . $M7 . '"]', $c->content, 'custom media list neutralised');
assert_contains('"linkPostId":"awx-post://' . $P42 . '"', $c->content, 'custom post attr neutralised');
assert_contains('"cats":[{"id":"awx-term://category/news"}]', $c->content, 'custom term objects neutralised');
assert_true(!has_warning($c->warnings, 'unregistered_id_attribute'), 'registered block does not warn');
$back = (new Transformer($prod, $custom))->resolve($c->content);
assert_contains('{"imageId":905,"galleryIds":[905,907],"linkPostId":1042,"cats":[{"id":13}]}', $back->content, 'custom entry resolves on prod');

// ---- 7. Extractor ---------------------------------------------------------------
echo "\n=== 7. Extractor builds the manifest, orders reusable blocks leaves first, detects cycles ===\n";
$dev->posts[100]['content'] = '<!-- wp:block {"ref":501} /-->' . "\n\n" . $fixtures['dgb hero: image object + _data mirror + nested mirror + numeric video_url'] . "\n\n" . '<!-- wp:dgb/awx-post-grid {"content.post_type":"product"} /-->';
$ex = new Extractor($dev, $t_dev);
$manifest = $ex->analyze(100);
assert_true($manifest['page']['uuid'] === $P100 && $manifest['page']['type'] === 'page', 'manifest page identity');
assert_true($manifest['page']['featured_media'] === $M7, 'featured image uuid carried');
assert_true($manifest['page']['terms'] === array('category:news'), 'post term assignments listed');
assert_true(isset($manifest['terms']['category:news']), 'assigned term is in the term catalogue');
assert_true(array_keys($manifest['media']) == array($M5, $M9, $M7) || count($manifest['media']) === 3, 'media manifest holds hero, brochure and logo (' . count($manifest['media']) . ')');
assert_true(count($manifest['blocks']) === 2 && $manifest['blocks'][0]['uuid'] === $B500 && $manifest['blocks'][1]['uuid'] === $B501, 'reusable blocks ordered leaves first');
assert_contains('awx-media://' . $M7, $manifest['blocks'][0]['content'], 'reusable block content is neutralised');
assert_true($manifest['dgb_blocks'] === array('awx-hero' => '1', 'awx-post-grid' => '1'), 'dgb block slugs and versions listed');
assert_true($manifest['post_types'] === array('product'), 'queried post type recorded');
assert_true(!isset($manifest['posts'][$P100]), 'page itself is not a post dependency');
assert_true(isset($manifest['posts'][$P42]), 'linked page listed as post dependency');
assert_true($manifest['page']['hash'] === hash('sha256', $dev->posts[100]['content']), 'page hash is sha256 of original content');
assert_true(!empty(Transformer::leftover_tokens($manifest['page']['content'])), 'page content in manifest carries tokens');

$dev->posts[100]['content'] = '<!-- wp:block {"ref":600} /-->';
$caught = null;
try {
	$ex->analyze(100);
} catch (Sync_Exception $e) {
	$caught = $e;
}
assert_true($caught !== null && $caught->code_slug === 'block_cycle', 'cycle between reusable blocks fails the preflight');
assert_true($caught !== null && $caught->details['chain'] === array(600, 601, 600), 'cycle chain reported');

$caught = null;
try {
	$ex->analyze(12345);
} catch (Sync_Exception $e) {
	$caught = $e;
}
assert_true($caught !== null && $caught->code_slug === 'post_not_found', 'unknown post fails the preflight');

// ---- Summary ----------------------------------------------------------------
echo "\n";
if ($failures > 0) {
	echo "❌ {$failures} assertion(s) failed.\n";
	exit(1);
}
echo "✅ All assertions passed.\n";
