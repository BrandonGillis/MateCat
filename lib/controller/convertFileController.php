<?php

set_time_limit(0);

include_once INIT::$MODEL_ROOT . "/queries.php";
include_once INIT::$UTILS_ROOT . "/cat.class.php";
include_once INIT::$UTILS_ROOT . "/fileFormatConverter.class.php";

class convertFileController extends ajaxcontroller {

	private $file_name;
	private $source_lang;
	private $target_lang;

	private $cache_days=10;

	private $intDir;
	private $errDir;

	public function __construct() {
		parent::__construct();
		$this->file_name = $this->get_from_get_post('file_name');
		$this->source_lang = $this->get_from_get_post("source_lang");
		$this->target_lang = $this->get_from_get_post("target_lang");

		$this->intDir = INIT::$UPLOAD_REPOSITORY.'/' . $_COOKIE['upload_session'];
		$this->errDir = INIT::$STORAGE_DIR.'/conversion_errors/' . $_COOKIE['upload_session'];

	}

	public function doAction() {
		if (empty($this->file_name)) {
			$this->result['errors'][] = array("code" => -1, "message" => "Error: missing file name.");
			return false;
		}

		$ext = pathinfo($this->file_name, PATHINFO_EXTENSION);

		$file_path = $this->intDir . '/' . $this->file_name;

		if (!file_exists($file_path)) {
			$this->result['errors'][] = array("code" => -6, "message" => "Error during upload. Please retry.");
			return -1;
		}
		$original_content = file_get_contents($file_path);
		$sha1 = sha1($original_content);

		if( INIT::$SAVE_SHASUM_FOR_FILES_LOADED ){
		$xliffContent = getXliffBySHA1($sha1, $this->source_lang, $this->target_lang,$this->cache_days);
		}
		if ( isset($xliffContent) && !empty($xliffContent)) {
			$xliffContent=  gzinflate($xliffContent);
			$res = $this->put_xliff_on_file($xliffContent, $this->intDir);
			if ($res == -1) {
				return -1;
			}
			return 0;
		} else {
			$original_content_zipped = gzdeflate($original_content, 5);
			unset($original_content);

			$converter = new fileFormatConverter();
			if(strpos($this->target_lang,',')!==FALSE){
				$single_language=explode(',',$this->target_lang);
				$single_language=$single_language[0];
			}else{
				$single_language=$this->target_lang;

			}
			$convertResult = $converter->convertToSdlxliff($file_path, $this->source_lang, $single_language);

			if ($convertResult['isSuccess'] == 1) {
				//$uid = $convertResult['uid']; // va inserito nel database
				$xliffContent = $convertResult['xliffContent'];
				$xliffContentZipped = gzdeflate($xliffContent, 5);
				/* if (!$this->checkSegmentsNumber($xliffContent)) {
				   $this->result['code'] = 0;
				   $this->result['errors'][] = array("code" => -2, "message" => 'No segments found in this file!');
				   unlink($file_path);
				   return -1;
				   }
				   if (!is_dir($this->intDir . "_converted")) {
				   mkdir($this->intDir . "_converted");
				   };
				   $this->result['code'] = 1;

				   file_put_contents("$this->intDir" . "_converted/$this->file_name.sdlxliff", $xliffContent);
				 * 
				 */
				if( INIT::$SAVE_SHASUM_FOR_FILES_LOADED ){
					$res_insert = insertFileIntoMap($sha1, $this->source_lang, $this->target_lang, $original_content_zipped, $xliffContentZipped);
				}
				unset ($xliffContentZipped);
				$res = $this->put_xliff_on_file($xliffContent, $this->intDir);
				if ($res == -1) {
					return -1;
				}
				return 0;

			} else {

				if (
                    stripos($convertResult['errorMessage'] ,"failed to create SDLXLIFF.") !== false ||
                    stripos($convertResult['errorMessage'] ,"COM target does not implement IDispatch") !== false
                ) {
					$convertResult['errorMessage'] = "Error: failed importing file.";
				} else if( stripos($convertResult['errorMessage'] ,"Unable to open Excel file - it may be password protected") !== false ) {
                    $convertResult['errorMessage'] = $convertResult['errorMessage'] . " Try to remove protection using the Unprotect Sheet command on Windows Excel.";
                }

				$this->result['code'] = 0;
				$this->result['errors'][] = array("code" => -1, "message" => $convertResult['errorMessage']);
//				log::doLog("ERROR MESSAGE : " . $convertResult['errorMessage']);

				return -1;
			}
		}
	}

	private function put_xliff_on_file($xliffContent) {
		$file_path = $this->intDir . '/' . $this->file_name;
		if (!$this->checkSegmentsNumber($xliffContent)) {
			$this->result['code'] = 0;
			$this->result['errors'][] = array("code" => -2, "message" => 'Error: no segments found in this file!');
			unlink($file_path);
			return -1;
		}
		if (!is_dir($this->intDir . "_converted")) {
			mkdir($this->intDir . "_converted");
		};

		file_put_contents("$this->intDir" . "_converted/$this->file_name.sdlxliff", $xliffContent);
		$this->result['code'] = 1;
		return 1;
	}

	private function checkSegmentsNumber($xliffContent) {
		return 1; // this function is bypassed because this is not the right way to tempt to find translatable content: the g tag could not appear but the file could still contain translatable content
		$found = preg_match_all('/<g id="[^"]+">/', $xliffContent, $res);
		if (!$found or $found == 0) {
			return 0;
		}
		return 1; //segnaposto
	}

}

?>
