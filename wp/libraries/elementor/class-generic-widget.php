<?php
class Elementor_Generic_Widget extends \Elementor\Widget_Base {

    // Static property to hold all widget configs for the entire request
    private static $widget_configs = [];
    // This instance's specific config
    private $config;

    // Static method to add a config to our cache
    public static function add_config(array $config): void {
        if (!empty($config['name'])) {
            self::$widget_configs[$config['name']] = $config;
        }
    }

   public function __construct($data = [], $args = []) {
        // 1. Get the unique widget name from the arguments FIRST.
        // We look in $args directly because the parent hasn't processed it yet.
        $widget_name = $args['widget_name'] ?? '';

        // 2. Load the configuration into the instance property.
        if ($widget_name && isset(self::$widget_configs[$widget_name])) {
            $this->config = self::$widget_configs[$widget_name];
        } else {
            // Set a default empty config to prevent errors in other methods.
            $this->config = [];
        }

        // 3. NOW, call the parent constructor. It will call get_name(), get_title(),
        // etc., which will now work correctly because $this->config is populated.
        parent::__construct($data, $args);
    }

    // --- REVISED GET_NAME ---
    // Now that the config is set before the parent runs, we can simplify this
    // to be consistent with all the other get_* methods.
    public function get_name(): string {
        return $this->config['name'] ?? 'generic_placeholder';
    }
    public function get_title(): string {
        return $this->config['title'] ?? esc_html__('Generic Widget', 'elementor-addon');
    }

    public function get_icon(): string {
        return $this->config['icon'] ?? 'eicon-code';
    }

    public function get_categories(): array {
        return $this->config['categories'] ?? ['basic'];
    }

    public function get_keywords(): array {
        return $this->config['keywords'] ?? [];
    }

    protected function register_controls(): void {
        //run service $this->config['controls']
        if (!empty($this->config['controls_service'])) {
          $sections =\aw2_library::service_run($this->config['controls_service'],null,null,'service');
          $this->config['controls'] = $sections['sections'] ?? '';
        }

        if (empty($this->config['controls'])) {
            return;
        }

        // ... (The rest of the register_controls method can stay exactly the same) ...
        foreach ( $this->config['controls'] as $section ) {
            $this->start_controls_section(
                $section['section_id'],
                [
                    'label' => esc_html__( $section['section_label'], 'elementor-addon' ),
                    'tab' => $this->get_tab_name( $section['section_tab'] ),
                ]
            );
            foreach ( $section['fields'] as $field_str ) {
                $field=json_decode($field_str,true);
                $field['type'] = constant('\Elementor\Controls_Manager::' . strtoupper($field['type']));
                $this->add_control($field['id'], $field);
            }
            $this->end_controls_section();
        }
    }

    // Add a helper method for safety
    private function get_tab_name(string $tab_string): string {
        if ($tab_string === 'style') {
            return \Elementor\Controls_Manager::TAB_STYLE;
        }
        return \Elementor\Controls_Manager::TAB_CONTENT;
    }
    
    protected function render(): void {
        // Use the defensive 'render' logic from the previous fix
        $settings = $this->get_settings_for_display();

         //run service $this->config['controls']
        if (!empty($this->config['render_service'])) {
           $this->config['render_html']=\aw2_library::service_run($this->config['render_service'],$settings,null,'service');
        }
       //   \util::var_dump($settings);
        $html = $this->config['render_html'] ?? '';
        preg_match_all('/\{\{(.*?)\}\}/', $html, $matches);
        if (!empty($matches[1])) {
            foreach ($matches[1] as $placeholder) {
                $key = trim($placeholder);
                if (isset($settings[$key])) {
                    $html = str_replace('{{' . $placeholder . '}}', $settings[$key], $html);
                }
            }
        }
        echo $html;
    }

    protected function content_template(): void {
        //run service $this->config['controls']
        if (!empty($this->config['render_service'])) {
           $this->config['render_html']=\aw2_library::service_run($this->config['render_service'],$settings,null,'service');
        }
        // Use the defensive 'content_template' logic from the previous fix
        $html_template = $this->config['render_html'] ?? '';
        ?>
        <#
         var html = <?php echo json_encode( $html_template ); ?>;

            // This function will be called to replace our custom shortcodes
            function replaceShortcodes( template ) {
                // Regex to find all instances of [template.get ... /]
                return template.replace(
                    /\[template\.get\s+(.*?)\s*\/?\]/g,
                    function( match, key ) {
                        key = key.trim();
                        // Check if the setting exists and return its value
                        if ( settings.hasOwnProperty( key ) ) {
                            return settings[key];
                        }
                        return ''; // Return empty string if not found
                    }
                );
            }

            // Initial render
            var renderedHtml = replaceShortcodes( html );

            // This part is a bit more advanced: it ensures that when a setting is updated,
            // the entire template is re-rendered to reflect the change.
            // We listen to changes on any setting.
            _.each( settings, function( value, key ) {
                view.addRenderAttribute( key, 'on', 'change', function() {
                    var newHtml = replaceShortcodes( html );
                    view.$el.html( newHtml );
                } );
            } );

            print( renderedHtml );
        #>
        <?php
    }
}