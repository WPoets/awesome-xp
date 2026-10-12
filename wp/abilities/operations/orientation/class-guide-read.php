<?php
namespace aw2\abilities\operations\orientation;

use aw2\abilities\operations\Operation_Base;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Ability: awesome/guide-read
 *
 * Returns the Awesome overview, authoring workflow, and topic index when no topic
 * is given. When a topic is passed, returns that section of the reference.
 */
class Guide_Read extends Operation_Base
{
    private $guides_dir;

    public function __construct()
    {
        $this->guides_dir = dirname(dirname(__DIR__)) . '/guides/';
    }

    public function execute(array $params = [])
    {
        $topic = isset($params['topic']) ? sanitize_title(trim($params['topic'])) : '';
        $available_topics = [
            'overview'   => 'Platform overview, authoring lifecycle, and core reuse rules',
            'lifecycle'  => 'Shortcode execution lifecycle and 4-stage processor sequence',
            'syntax'     => 'Shortcode syntax, typecasting prefixes, and variable syntax',
            'scopes'     => 'Module, template, and block (do) scoping rules',
            'safety'     => 'SQL safety, the esc library, and parameterization',
            'css-tokens' => 'Awesome CSS Design System: Two-layer token model and awx tokens',
            'block-css'  => 'Writing block CSS: Tokens -> modifiers -> patterns -> blocks',
            'pitfalls'   => 'Common pitfalls, disallowed patterns, and lint rules'
        ];

        if (empty($topic) || $topic === 'overview') {
            $file = $this->guides_dir . 'overview.md';
            $content = file_exists($file) ? file_get_contents($file) : '# Awesome XP Guide';
            return $this->success([
                'topic'            => 'overview',
                'content'          => $content,
                'available_topics' => $available_topics
            ]);
        }

        if (!array_key_exists($topic, $available_topics)) {
            return $this->error(
                "Unknown guide topic '{$topic}'.",
                "Call awesome/guide-read without a topic to see the full topic index.",
                'topic_not_found',
                ['available_topics' => array_keys($available_topics)]
            );
        }

        $file = $this->guides_dir . "{$topic}.md";
        if (!file_exists($file)) {
            return $this->error(
                "Guide file for topic '{$topic}' was not found.",
                "Call awesome/guide-read with a valid topic.",
                'file_missing'
            );
        }

        $content = file_get_contents($file);
        return $this->success([
            'topic'            => $topic,
            'content'          => $content,
            'related_topics'   => array_diff(array_keys($available_topics), [$topic])
        ]);
    }
}
