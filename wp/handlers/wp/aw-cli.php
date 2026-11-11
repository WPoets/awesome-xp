<?php

if(defined( 'WP_CLI' ) && WP_CLI){
    add_action("plugins_loaded", "regwp");
}

function regwp(){
    class aw2_commands extends WP_CLI_Command {
        /**
         * Run shortcode background
         */
        function run($args) {
            if (!preg_match('/^[A-Za-z0-9_.\-]+$/', (string)$args[0])) {
                WP_CLI::error('Invalid service name');
            }
            $params = json_decode(isset($args[1]) ? $args[1] : '{}', true);
            $str = '';
            foreach((array)$params as $arg => $val){
                if (!preg_match('/^[A-Za-z0-9_.\-]+$/', (string)$arg)) continue;
                if (is_array($val) || is_object($val)) $val = json_encode($val);
                // values are wrapped in single quotes: strip quote and bracket characters
                $val = str_replace(array("'", '[', ']'), '', (string)$val);
                $str .= "$arg='$val' ";
            }
            
            echo aw2_library::parse_shortcode("[" . $args[0] . " " . $str . "/]");
        }
    }
    
    WP_CLI::add_command( 'aw2', 'aw2_commands' );
}

\aw2_library::add_service('awcli.run','run cli command',['namespace'=>__NAMESPACE__]);
function run($atts,$content=null,$shortcode = array()){
    
    if(\aw2_library::pre_actions('all',$atts,$content)==false)return;
    
    extract(\aw2_library::shortcode_atts( array(
        'main'=>null
    ), $atts) );
    
    unset($atts['main']);
    unset($atts['set']);
    unset($atts['dump']);
    
    if (!preg_match('/^[A-Za-z0-9_.\-]+$/', (string)$main)) {
        return \aw2_library::post_actions('all', '_error', $atts);
    }
    $args = json_encode($atts);
    $cmd = sprintf("wp aw2 run %s %s >/dev/null 2>/dev/null & echo $!", escapeshellarg($main), escapeshellarg($args));
    $op = shell_exec($cmd);
    
    return \aw2_library::post_actions('all', trim($op), $atts);
}

\aw2_library::add_service('awcli.isrunning','run cli command',['namespace'=>__NAMESPACE__]);
function isrunning($atts,$content=null,$shortcode = array()){

    if(\aw2_library::pre_actions('all',$atts,$content)==false)return;
    
    extract(\aw2_library::shortcode_atts( array(
        'main'=>null,
        'pid'=>null
    ), $atts) );

    try{
        $result = shell_exec(sprintf("ps -p %d --no-headers -o pid,time,command", $pid));
    }catch(Exception $e){}

    return \aw2_library::post_actions('all', trim($result), $atts);
}

\aw2_library::add_service('awcli.allrunning','get all running processes',['namespace'=>__NAMESPACE__]);
function allrunning($atts,$content=null,$shortcode = array()){
    $result = shell_exec(sprintf("pgrep -f 'wp aw2 run'"));
    $result = explode(PHP_EOL, $result);

    return \aw2_library::post_actions('all', array_filter($result), $atts);
}

\aw2_library::add_service('awcli.killpid','kill process',['namespace'=>__NAMESPACE__]);
function killpid($atts,$content=null,$shortcode = array()){

    if(\aw2_library::pre_actions('all',$atts,$content)==false)return;
    
    extract(\aw2_library::shortcode_atts( array(
        'main'=>null,
        'pid'=>null
    ), $atts) );

    try{
        $result = shell_exec(sprintf("kill %d", $pid));
    }catch(Exception $e){}

    return \aw2_library::post_actions('all', trim($result), $atts);
}