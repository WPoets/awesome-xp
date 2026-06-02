<?php
namespace aw2\elementor_widgets;

\aw2_library::add_service('element_widgets','Handles the registration of ctp, less variables etc.',['namespace'=>__NAMESPACE__]);

function unhandled($atts,$content=null,$shortcode){
	if(\aw2_library::pre_actions('all',$atts,$content)==false)return;

	$pieces=$shortcode['tags'];

	if(count($pieces)!=2)return 'error:You must have exactly two parts to the query shortcode';
	
	$register=new awesome2_elementor_widgets($pieces[1],$atts,$content);
	if($register->status==false){
		return \aw2_library::get('errors');
	}
	$return_value =$register->run();
	
	$return_value=\aw2_library::post_actions('all',$return_value,$atts);
	return $return_value;
	
}

class awesome2_elementor_widgets{
	public $action=null;
	public $atts=null;
	public $content=null;
	public $status=false;
	
	function __construct($action,$atts,$content=null){
     if (method_exists($this, $action)){
		$this->action=$action;
		$this->atts=$atts;
		$this->content=trim($content);
		$this->status=true;
	 }
	}
	function run(){
     if (method_exists($this, $this->action))
		return call_user_func(array($this, $this->action));
     else
		\aw2_library::set_error('Register Method does not exist'); 
	}
	
	function att($el,$default=null){
		if(array_key_exists($el,$this->atts))
			return $this->atts[$el];
		return $default;
	}

	function args(){
		if($this->content==null || $this->content==''){
			$return_value=array();	
		}
		else{
			$json=\aw2_library::clean_specialchars($this->content);
			$json=\aw2_library::parse_shortcode($json);		
			$return_value=json_decode($json, true);
			if(is_null($return_value)){
				\aw2_library::set_error('Invalid JSON' . $this->content); 
				$return_value=array();	
			}
		}

		$arg_list = func_get_args();
		foreach($arg_list as $arg){
			if(array_key_exists($arg,$this->atts))
				$return_value[$arg]=$this->atts[$arg];
		}
			return $return_value;
	}
	
	function register(){
		$elementor_widgets=&\aw2_library::get_array_ref('elementor_widgets');
		$new=$this->args();
		$elementor_widgets[]=$new;
	}
	
	
	
}	
