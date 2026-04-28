<?php
/**
 * Blog Post Card Template
 * 
 * Demonstrates working with radio buttons and checkboxes
 * 
 * Available variables:
 * - $data: Array of all field values
 * - $content: Rendered inner blocks HTML
 * - $config: Block configuration
 */

// Extract data
var_dump($data['content']);
$title = isset($data['content']['title']) ? $data['content']['title'] : '';
$excerpt = isset($data['content']['excerpt']) ? $data['content']['excerpt'] : '';
$image = isset($data['content']['image']) ? $data['content']['image'] : null;
$date = isset($data['content']['date']) ? $data['content']['date'] : '';
$author = isset($data['content']['author']) ? $data['content']['author'] : '';
$read_time = isset($data['content']['readTime']) ? $data['content']['readTime'] : '';

// Display options (checkboxes - array)
$show_elements = isset($data['display']['showElements']) ? $data['display']['showElements'] : array();
$show_category = isset($data['display']['showCategoryBadge']) ? $data['display']['showCategoryBadge'] : false;
$hover_effect = isset($data['display']['hoverEffect']) ? $data['display']['hoverEffect'] : false;

// Style options (radio buttons - strings)
$layout = isset($data['style']['layout']) ? $data['style']['layout'] : 'vertical';
$image_ratio = isset($data['style']['imageRatio']) ? $data['style']['imageRatio'] : '16-9';
$text_align = isset($data['style']['textAlign']) ? $data['style']['textAlign'] : 'left';
$card_style = isset($data['style']['cardStyle']) ? $data['style']['cardStyle'] : 'bordered';

// Advanced options
$button_text = isset($data['advanced']['buttonText']) ? $data['advanced']['buttonText'] : 'Read More';
$button_url = isset($data['advanced']['buttonUrl']) ? $data['advanced']['buttonUrl'] : '#';
$category_label = isset($data['advanced']['categoryLabel']) ? $data['advanced']['categoryLabel'] : '';
$open_new_tab = isset($data['advanced']['openNewTab']) ? $data['advanced']['openNewTab'] : false;

// Helper function to check if element should be shown
function should_show($element, $show_elements) {
    return is_array($show_elements) && in_array($element, $show_elements);
}

// Build CSS classes
$card_classes = array(
    'blog-post-card',
    'blog-post-card--' . $layout,
    'blog-post-card--' . $card_style,
    'text-' . $text_align
);

if ($hover_effect) {
    $card_classes[] = 'blog-post-card--hover';
}

?>
<article class="<?php echo esc_attr(implode(' ', $card_classes)); ?>">
    
    <?php if (should_show('image', $show_elements) && $image): ?>
        <div class="blog-post-card__image-wrapper aspect-ratio-<?php echo esc_attr($image_ratio); ?>">
            <img 
                src="<?php echo esc_url($image['url']); ?>" 
                alt="<?php echo esc_attr($image['alt']); ?>"
                class="blog-post-card__image"
            />
            
            <?php if ($show_category && $category_label): ?>
                <span class="blog-post-card__category-badge">
                    <?php echo esc_html($category_label); ?>
                </span>
            <?php endif; ?>
        </div>
        
        <?php if (should_show('excerpt', $show_elements) && $excerpt): ?>
            <p class="blog-post-card__excerpt">
                <?php echo esc_html($excerpt); ?>
            </p>
        <?php endif; ?>
        
        <?php if (should_show('button', $show_elements)): ?>
            <div class="blog-post-card__actions">
                <a 
                    href="<?php echo esc_url($button_url); ?>" 
                    class="blog-post-card__button"
                    <?php if ($open_new_tab): ?>
                        target="_blank" 
                        rel="noopener noreferrer"
                    <?php endif; ?>
                >
                    <?php echo esc_html($button_text); ?>
                    <svg class="icon icon-arrow" width="16" height="16" viewBox="0 0 16 16">
                        <path d="M8 1l7 7-7 7-1.5-1.5L11 9H1V7h10L6.5 2.5z"/>
                    </svg>
                </a>
            </div>
        <?php endif; ?>
        
    </div>
    
</article>

<?php
/**
 * Example CSS to add to your stylesheet:
 * 
 * .blog-post-card {
 *     display: flex;
 *     border-radius: 8px;
 *     overflow: hidden;
 *     transition: transform 0.3s ease, box-shadow 0.3s ease;
 * }
 * 
 * .blog-post-card--vertical {
 *     flex-direction: column;
 * }
 * 
 * .blog-post-card--horizontal {
 *     flex-direction: row;
 * }
 * 
 * .blog-post-card--overlay {
 *     position: relative;
 *     color: #fff;
 * }
 * 
 * .blog-post-card--bordered {
 *     border: 1px solid #e2e4e7;
 * }
 * 
 * .blog-post-card--shadow {
 *     box-shadow: 0 2px 8px rgba(0,0,0,0.1);
 * }
 * 
 * .blog-post-card--elevated {
 *     box-shadow: 0 4px 16px rgba(0,0,0,0.15);
 * }
 * 
 * .blog-post-card--hover:hover {
 *     transform: translateY(-4px);
 *     box-shadow: 0 8px 24px rgba(0,0,0,0.2);
 * }
 * 
 * .blog-post-card__image-wrapper {
 *     position: relative;
 *     overflow: hidden;
 * }
 * 
 * .aspect-ratio-16-9 {
 *     aspect-ratio: 16 / 9;
 * }
 * 
 * .aspect-ratio-4-3 {
 *     aspect-ratio: 4 / 3;
 * }
 * 
 * .aspect-ratio-1-1 {
 *     aspect-ratio: 1 / 1;
 * }
 * 
 * .aspect-ratio-3-2 {
 *     aspect-ratio: 3 / 2;
 * }
 * 
 * .blog-post-card__image {
 *     width: 100%;
 *     height: 100%;
 *     object-fit: cover;
 * }
 * 
 * .blog-post-card__category-badge {
 *     position: absolute;
 *     top: 12px;
 *     right: 12px;
 *     background: #2271b1;
 *     color: #fff;
 *     padding: 4px 12px;
 *     border-radius: 4px;
 *     font-size: 12px;
 *     font-weight: 600;
 * }
 * 
 * .blog-post-card__content {
 *     padding: 20px;
 * }
 * 
 * .blog-post-card__title {
 *     margin: 0 0 12px;
 *     font-size: 24px;
 *     line-height: 1.3;
 * }
 * 
 * .blog-post-card__meta {
 *     display: flex;
 *     flex-wrap: wrap;
 *     gap: 16px;
 *     margin-bottom: 12px;
 *     font-size: 14px;
 *     color: #666;
 * }
 * 
 * .blog-post-card__meta span {
 *     display: flex;
 *     align-items: center;
 *     gap: 4px;
 * }
 * 
 * .blog-post-card__excerpt {
 *     margin: 0 0 16px;
 *     color: #555;
 *     line-height: 1.6;
 * }
 * 
 * .blog-post-card__button {
 *     display: inline-flex;
 *     align-items: center;
 *     gap: 8px;
 *     padding: 10px 20px;
 *     background: #2271b1;
 *     color: #fff;
 *     text-decoration: none;
 *     border-radius: 4px;
 *     transition: background 0.2s ease;
 * }
 * 
 * .blog-post-card__button:hover {
 *     background: #135e96;
 * }
 * 
 * .text-left {
 *     text-align: left;
 * }
 * 
 * .text-center {
 *     text-align: center;
 * }
 * 
 * .text-right {
 *     text-align: right;
 * }
 */
?>
    <?php endif; ?>
    
    <div class="blog-post-card__content">
        
        <?php if (should_show('title', $show_elements) && $title): ?>
            <h3 class="blog-post-card__title">
                <?php echo esc_html($title); ?>
            </h3>
        <?php endif; ?>
        
        <div class="blog-post-card__meta">
            <?php if (should_show('author', $show_elements) && $author): ?>
                <span class="blog-post-card__author">
                    <svg class="icon" width="16" height="16" viewBox="0 0 16 16">
                        <path d="M8 8a3 3 0 100-6 3 3 0 000 6zm0 1c-2.67 0-8 1.34-8 4v2h16v-2c0-2.66-5.33-4-8-4z"/>
                    </svg>
                    <?php echo esc_html($author); ?>
                </span>
            <?php endif; ?>
            
            <?php if (should_show('date', $show_elements) && $date): ?>
                <span class="blog-post-card__date">
                    <svg class="icon" width="16" height="16" viewBox="0 0 16 16">
                        <path d="M14 2h-1V1a1 1 0 00-2 0v1H5V1a1 1 0 00-2 0v1H2a2 2 0 00-2 2v10a2 2 0 002 2h12a2 2 0 002-2V4a2 2 0 00-2-2zM2 14V6h12v8H2z"/>
                    </svg>
                    <?php echo esc_html(date('F j, Y', strtotime($date))); ?>
                </span>
            <?php endif; ?>
            
            <?php if (should_show('readTime', $show_elements) && $read_time): ?>
                <span class="blog-post-card__read-time">
                    <svg class="icon" width="16" height="16" viewBox="0 0 16 16">
                        <path d="M8 0a8 8 0 100 16A8 8 0 008 0zm0 14a6 6 0 110-12 6 6 0 010 12zm1-6V4H7v5l4 2 1-2-3-1z"/>
                    </svg>
                    <?php echo esc_html($read_time); ?>
                </span>
            <?php endif; ?>
        </div>