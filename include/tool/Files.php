<?php
defined('is_running') or die('Not an entry point...');

/**
 * Contains functions for working with data files and directories
 *
 */
class gpFiles{

	static $last_modified; 						//the modified time of the last file retrieved with gpFiles::Get();
	static $last_version; 						//the gpEasy version of the last file retrieved with gpFiles::Get();
	static $last_stats			= array(); 		//the stats of the last file retrieved with gpFiles::Get();
	static $last_meta			= array(); 		//the meta data of the last file retrieved with gpFiles::Get();


	/**
	 * Get array from data file
	 * Example:
	 * $config = gpFiles::Get('_site/config','config'); or $config = gpFiles::Get('_site/config');
	 * @since 4.4b1
	 *
	 */
	static function Get( $file, $var_name=false ){
		global $dataDir;

		self::$last_modified	= null;
		self::$last_version		= null;
		self::$last_stats		= array();
		self::$last_meta		= array();


		if( !$var_name ){
			$var_name	= basename($file);
		}

		if( strpos($file,$dataDir) !== 0 ){
			$file = $dataDir.'/data/'.ltrim($file,'/').'.php';
		}

		//json
		if( gp_data_type === '.json' ){
			return self::Get_Json($file,$var_name);
		}

		if( !file_exists($file) ){
			return array();
		}

		include($file);
		if( !isset(${$var_name}) || !is_array(${$var_name}) ){
			return array();
		}


		// For data files older than gpEasy 3.0
		if( !isset($file_stats['modified']) ){
			$file_stats['modified'] = $fileModTime;
		}
		if( !isset($file_stats['gpversion']) ){
			$file_stats['gpversion'] = $fileVersion;
		}

		// File stats
		self::$last_modified		= $fileModTime;
		self::$last_version			= $fileVersion;
		self::$last_stats			= $file_stats;
		if( isset($meta_data) ){
			self::$last_meta		= $meta_data;
		}


		return ${$var_name};
	}


	/**
	 * Experimental
	 *
	 */
	private static function Get_Json($file,$var_name){

		$file		= substr($file,0,-4).'.gpjson';

		if( !file_exists($file) ){
			return array();
		}

		$contents	= file_get_contents($file);
		$data		= json_decode($contents,true);

		if( !isset($data[$var_name]) || !is_array($data[$var_name]) ){
			return array();
		}


		// File stats
		self::$last_modified		= $data['file_stats']['modified'];
		self::$last_version			= $data['file_stats']['gpversion'];
		self::$last_stats			= $data['file_stats'];
		self::$last_meta			= $data['meta_data'];


		return $data[$var_name];

	}

	/**
	 * Get the raw contents of a data file
	 *
	 */
	static function GetRaw($file){
		global $dataDir;

		if( strpos($file,$dataDir) !== 0 ){
			$file = $dataDir.'/data/'.ltrim($file,'/').'.php';
		}

		if( gp_data_type === '.json' ){
			$file		= substr($file,0,-4).'.gpjson';
		}

		return file_get_contents($file);
	}

	static function Exists($file){
		global $dataDir;

		if( strpos($file,$dataDir) !== 0 ){
			$file = $dataDir.'/data/'.ltrim($file,'/').'.php';
		}

		if( gp_data_type === '.json' ){
			$file		= substr($file,0,-4).'.gpjson';
		}

		return file_exists($file);
	}


	/**
	 * Read directory and return an array with files corresponding to $filetype
	 *
	 * @param string $dir The path of the directory to be read
	 * @param mixed $filetype If false, all files in $dir will be included. false=all,1=directories,'php'='.php' files
	 * @return array() List of files in $dir
	 */
	static function ReadDir($dir,$filetype='php'){
		$files = array();
		if( !file_exists($dir) ){
			return $files;
		}
		$dh = @opendir($dir);
		if( !$dh ){
			return $files;
		}

		while( ($file = readdir($dh)) !== false){
			if( $file == '.' || $file == '..' ){
				continue;
			}

			//get all
			if( $filetype === false ){
				$files[$file] = $file;
				continue;
			}

			//get directories
			if( $filetype === 1 ){
				$fullpath = $dir.'/'.$file;
				if( is_dir($fullpath) ){
					$files[$file] = $file;
				}
				continue;
			}


			$dot = strrpos($file,'.');
			if( $dot === false ){
				continue;
			}

			$type = substr($file,$dot+1);

			//if $filetype is an array
			if( is_array($filetype) ){
				if( in_array($type,$filetype) ){
					$files[$file] = $file;
				}
				continue;
			}

			//if $filetype is a string
			if( $type == $filetype ){
				$file = substr($file,0,$dot);
				$files[$file] = $file;
			}

		}
		closedir($dh);

		return $files;
	}


	/**
	 * Read all of the folders and files within $dir and return them in an organized array
	 *
	 * @param string $dir The directory to be read
	 * @return array() The folders and files within $dir
	 *
	 */
	static function ReadFolderAndFiles($dir){
		$dh = @opendir($dir);
		if( !$dh ){
			return array();
		}

		$folders = array();
		$files = array();
		while( ($file = readdir($dh)) !== false){
			if( strpos($file,'.') === 0){
				continue;
			}

			$fullPath = $dir.'/'.$file;
			if( is_dir($fullPath) ){
				$folders[] = $file;
			}else{
				$files[] = $file;
			}
		}
		natcasesort($folders);
		natcasesort($files);
		return array($folders,$files);
	}


	/**
	 * Clean a string for use as a page label (displayed title)
	 * Similar to CleanTitle() but less restrictive
	 *
	 * @param string $title The title to be cleansed
	 * @return string The cleansed title
	 */
	static function CleanLabel($title=''){

		$title = str_replace(array('"'),array(''),$title);
		$title = str_replace(array('<','>'),array('_'),$title);
		$title = trim($title);

		// Remove control characters
		return preg_replace( '#[[:cntrl:]]#u', '', $title ) ; // 	[\x00-\x1F\x7F]
	}


	/**
	 * Clean a string of html that may be used as file content
	 *
	 * @param string $text The string to be cleansed. Passed by reference
	 */
	static function CleanText(&$text){
		includeFile('tool/editing.php');
		gp_edit::tidyFix($text);
		gpFiles::rmPHP($text);
		gpFiles::FixTags($text);
		$text = gpPlugin::Filter('CleanText',array($text));
	}

	/**
	 * Use gpEasy's html parser to check the validity of $text
	 *
	 * @param string $text The html content to be checked. Passed by reference
	 */
	static function FixTags(&$text){
		includeFile('tool/HTML_Output.php');
		$gp_html_output = new gp_html_output($text);
		$text = $gp_html_output->result;
	}

	/**
	 * Remove php tags from $text
	 *
	 * @param string $text The html content to be checked. Passed by reference
	 */
	static function rmPHP(&$text){
		$search = array('<?','<?php','?>');
		$replace = array('&lt;?','&lt;?php','?&gt;');
		$text = str_replace($search,$replace,$text);
	}

	/**
	 * Removes any NULL characters in $string.
	 * @since 3.0.2
	 * @param string $string
	 * @return string
	 */
	static function NoNull($string){
		$string = preg_replace('/\0+/', '', $string);
		return preg_replace('/(\\\\0)+/', '', $string);
	}


	/**
	 * Save the content for a new page in /data/_pages/<title>
	 * @since 1.8a1
	 *
	 */
	static function NewTitle($title, $section_content = false, $type='text'){

		// get the file for the title
		if( empty($title) ){
			return false;
		}
		$file = gpFiles::PageFile($title);
		if( !$file ){
			return false;
		}

		// organize section data
		$file_sections = array();
		if( is_array($section_content) && isset($section_content['type']) ){
			$file_sections[0]	= $section_content;
		}elseif( is_array($section_content) ){
			$file_sections		= $section_content;
		}else{
			$file_sections[0] = array(
				'type'			=> $type,
				'content'		=> $section_content
				);
		}

		// add meta data
		$meta_data = array(
			'file_number'	=> gpFiles::NewFileNumber(),
			'file_type'		=> $type,
			);


		return gpFiles::SaveData($file,'file_sections',$file_sections,$meta_data);
	}

	/**
	 * Return the data file location for a title
	 * Since v4.6, page files are within a subfolder
	 * As of v2.3.4, it defaults to an index based file name but falls back on title based file name for installation and backwards compatibility
	 *
	 *
	 * @param string $title
	 * @return string The path of the data file
	 */
	static function PageFile($title){
		global $dataDir, $config, $gp_index;


		$index_path = false;
		if( gp_index_filenames && isset($gp_index[$title]) && isset($config['gpuniq']) ){

			// page.php
			$index_path = $dataDir.'/data/_pages/'.substr($config['gpuniq'],0,7).'_'.$gp_index[$title].'/page.php';
			if( file_exists($index_path) ){
				return $index_path;
			}


			// without folder -> rename it
			$old_index = $dataDir.'/data/_pages/'.substr($config['gpuniq'],0,7).'_'.$gp_index[$title].'.php';
			if( file_exists($old_index) ){
				if( gpFiles::Rename($old_index, $index_path) ){
					return $index_path;
				}
				return $old_index;
			}

		}


		//using file name instead of index
		$normal_path = $dataDir.'/data/_pages/'.str_replace('/','_',$title).'/page.php';
		if( !$index_path || gpFiles::Exists($normal_path) ){
			return $normal_path;
		}

		//without folder -> rename it
		$old_path = $dataDir.'/data/_pages/'.str_replace('/','_',$title).'.php';
		if( gpFiles::Exists($old_path) ){
			if( $index_path && gpFiles::Rename($old_path, $index_path) ){
				return $index_path;
			}
			if( gpFiles::Rename($old_path, $normal_path) ){
				return $normal_path;
			}
			return $old_path;
		}

		return $index_path;
	}

	static function NewFileNumber(){
		global $config;

		includeFile('admin/admin_tools.php');

		if( !isset($config['file_count']) ){
			$config['file_count'] = 0;
		}
		$config['file_count']++;

		admin_tools::SaveConfig();

		return $config['file_count'];

	}

	/**
	 * Get the meta data for the specified file
	 *
	 * @param string $file
	 * @return array
	 */
	static function GetTitleMeta($file){
		gpFiles::Get($file,'meta_data');
		return gpFiles::$last_meta;
	}

	/**
	 * Return an array of info about the data file
	 *
	 */
	static function GetFileStats($file){


		$file_stats = gpFiles::Get($file,'file_stats');
		if( $file_stats ){
			return $file_stats;
		}

		return array('created'=> time());
	}


	/**
	 * Save a file with content and data to the server
	 * This function will be deprecated in future releases. Using it is not recommended
	 *
	 * @param string $file The path of the file to be saved
	 * @param string $contents The contents of the file to be saved
	 * @param string $code The data to be saved
	 * @param string $time The unix timestamp to be used for the $fileVersion
	 * @return bool True on success
	 */
	static function SaveFile($file,$contents,$code=false,$time=false){

		$result = gpFiles::FileStart($file,$time);
		if( $result !== false ){
			$result .= "\n".$code;
		}
		$result .= "\n\n?".">\n";
		$result .= $contents;

		return gpFiles::Save($file,$result);
	}

	/**
	 * Save raw content to a file to the server
	 *
	 * @param string $file The path of the file to be saved
	 * @param string $contents The contents of the file to be saved
	 * @return bool True on success
	 */
	static function Save($file,$contents){
		global $gp_not_writable;

		if( !self::WriteLock() ){
			return false;
		}

		$exists = gpFiles::Exists($file);

		//make sure directory exists
		if( !$exists ){
			$dir = common::DirName($file);
			if( !file_exists($dir) ){
				gpFiles::CheckDir($dir);
			}
		}


		$fp = @fopen($file,'wb');
		if( $fp === false ){
			$gp_not_writable[] = $file;
			return false;
		}

		if( !$exists ){
			@chmod($file,gp_chmod_file);
		}elseif( function_exists('opcache_invalidate') && substr($file,-4) === '.php' ){
			opcache_invalidate($file);
		}

		$return = fwrite($fp,$contents);
		fclose($fp);
		return ($return !== false);
	}

	/**
	 * Rename a file
	 * @since 4.6
	 */
	static function Rename($from,$to){
		global $gp_not_writable;

		if( !self::WriteLock() ){
			return false;
		}


		//make sure directory exists
		$dir = common::DirName($to);
		if( !file_exists($dir) && !gpFiles::CheckDir($dir) ){
			return false;
		}


		return rename($from, $to);
	}


	/**
	 * Get a write lock to prevent simultaneous writing
	 * @since 3.5.3
	 */
	static function WriteLock(){

		if( defined('gp_has_lock') ){
			return gp_has_lock;
		}

		$expires = gp_write_lock_time;
		if( self::Lock('write',gp_random,$expires) ){
			define('gp_has_lock',true);
			return true;
		}



		trigger_error('gpEasy write lock could not be obtained.');
		define('gp_has_lock',false);
		return false;
	}

	/**
	 * Get a lock
 	 * Loop and delay to wait for the removal of existing locks (maximum of about .2 of a second)
 	 *
 	 */
	static function Lock($file,$value,&$expires){
		global $dataDir;

		$tries			= 0;
		$lock_file		= $dataDir.'/data/_lock_'.sha1($file);
		$file_time		= 0;


		while($tries < 1000){

			if( !file_exists($lock_file) ){
				file_put_contents($lock_file,$value);
				usleep(100);

			}elseif( !$file_time ){
				$file_time		= filemtime($lock_file);
			}

			$contents = @file_get_contents($lock_file);
			if( $value === $contents ){
				@touch($lock_file);
				return true;
			}

			if( $file_time ){
				$elapsed = time() - $file_time;
				if( $elapsed > $expires ){
					@unlink( $lock_file);
				}
			}

			clearstatcache();
			usleep(100);
			$tries++;
		}

		if( $file_time ){
			$expires -= $elapsed;
		}

		return false;
	}

	/**
	 * Remove a lock file if the value matches
	 *
	 */
	static function Unlock($file,$value){
		global $dataDir;

		$lock_file = $dataDir.'/data/_lock_'.sha1($file);
		if( !file_exists($lock_file) ){
			return true;
		}

		$contents = @file_get_contents($lock_file);
		if( $contents === false ){
			return true;
		}
		if( $value === $contents ){
			unlink($lock_file);
			return true;
		}
		return false;
	}


	/**
	 * Save array(s) to a $file location
	 * Takes 2n+3 arguments
	 *
	 * @param string $file The location of the file to be saved
	 * @param string $varname The name of the variable being saved
	 * @param array $array The value of $varname to be saved
	 *
	 * @deprecated 4.3.5
	 */
	static function SaveArray(){

		if( gp_data_type === '.json' ){
			throw new Exception('SaveArray() cannot be used for json data saving');
		}


		$args = func_get_args();
		$count = count($args);
		if( ($count %2 !== 1) || ($count < 3) ){
			trigger_error('Wrong argument count '.$count.' for gpFiles::SaveArray() ');
			return false;
		}
		$file = array_shift($args);

		$file_stats = array();
		$data = '';
		while( count($args) ){
			$varname = array_shift($args);
			$array = array_shift($args);
			if( $varname == 'file_stats' ){
				$file_stats = $array;
			}else{
				$data .= gpFiles::ArrayToPHP($varname,$array);
				$data .= "\n\n";
			}
		}

		$data = gpFiles::FileStart($file,time(),$file_stats).$data;

		return gpFiles::Save($file,$data);
	}

	/**
	 * Save array to a $file location
	 *
	 * @param string $file The location of the file to be saved
	 * @param string $varname The name of the variable being saved
	 * @param array $array The value of $varname to be saved
	 * @param array $meta meta data to be saved along with $array
	 *
	 */
	static function SaveData($file, $varname, $array, $meta = array() ){
		global $dataDir;

		if( strpos($file,$dataDir) !== 0 ){
			$file = $dataDir.'/data/'.ltrim($file,'/').'.php';
		}


		if( gp_data_type === '.json' ){

			$file				= substr($file,0,-4).'.gpjson';

			$json				= self::FileStart_Json($file);
			$json[$varname]		= $array;
			$json['meta_data']	= $meta;
			$content			= json_encode($json);

		}else{
			$content	= gpFiles::FileStart($file);
			$content	.= gpFiles::ArrayToPHP($varname,$array);
			$content	.= "\n\n";
			$content	.= gpFiles::ArrayToPHP('meta_data',$meta);
		}

		return gpFiles::Save($file,$content);
	}

	/**
	 * Experimental
	 *
	 */
	private static function FileStart_Json($file, $time = false ){
		global $gpAdmin;

		if( $time === false ) $time = time();


		//file stats
		$file_stats					= gpFiles::GetFileStats($file);
		$file_stats['gpversion']	= gpversion;
		$file_stats['modified']		= $time;
		$file_stats['username']		= false;

		if( common::loggedIn() ){
			$file_stats['username'] = $gpAdmin['username'];
		}

		$json						= array();
		$json['file_stats']			= $file_stats;

		return $json;
	}


	/**
	 * Return the beginning content of a data file
	 *
	 */
	static function FileStart($file, $time=false, $file_stats = array() ){
		global $gpAdmin;

		if( $time === false ) $time = time();


		//file stats
		$file_stats = (array)$file_stats + gpFiles::GetFileStats($file);
		$file_stats['gpversion'] = gpversion;
		$file_stats['modified'] = $time;

		if( common::loggedIn() ){
			$file_stats['username'] = $gpAdmin['username'];
		}else{
			$file_stats['username'] = false;
		}

		return '<'.'?'.'php'
				. "\ndefined('is_running') or die('Not an entry point...');"
				. "\n".'$fileVersion = \''.gpversion.'\';' /* @deprecated 3.0 */
				. "\n".'$fileModTime = \''.$time.'\';' /* @deprecated 3.0 */
				. "\n".gpFiles::ArrayToPHP('file_stats',$file_stats)
				. "\n\n";
	}


	static function ArrayToPHP($varname,&$array){
		return '$'.$varname.' = '.var_export($array,true).';';
	}


	/**
	 * Insert a key-value pair into an associative array
	 *
	 * @param mixed $search_key Value to search for in existing array to insert before
	 * @param mixed $new_key Key portion of key-value pair to insert
	 * @param mixed $new_value Value portion of key-value pair to insert
	 * @param array $array Array key-value pair will be added to
	 * @param int $offset Offset distance from where $search_key was found. A value of 1 would insert after $search_key, a value of 0 would insert before $search_key
	 * @param int $length If length is omitted, nothing is removed from $array. If positive, then that many elements will be removed starting with $search_key + $offset
	 * @return bool True on success
	 */
	static function ArrayInsert($search_key,$new_key,$new_value,&$array,$offset=0,$length=0){

		$array_keys = array_keys($array);
		$array_values = array_values($array);

		$insert_key = array_search($search_key,$array_keys);
		if( ($insert_key === null) || ($insert_key === false) ){
			return false;
		}

		array_splice($array_keys,$insert_key+$offset,$length,$new_key);
		array_splice($array_values,$insert_key+$offset,$length,'fill'); //use fill in case $new_value is an array
		$array = array_combine($array_keys, $array_values);
		$array[$new_key] = $new_value;

		return true;
	}


	/**
	 * Replace a key-value pair in an associative array
	 * ArrayReplace() is a shortcut for using gpFiles::ArrayInsert() with $offset = 0 and $length = 1
	 */
	static function ArrayReplace($search_key,$new_key,$new_value,&$array){
		return gpFiles::ArrayInsert($search_key,$new_key,$new_value,$array,0,1);
	}



	/**
	 * Check recursively to see if a directory exists, if it doesn't attempt to create it
	 *
	 * @param string $dir The directory path
	 * @param bool $index Whether or not to add an index.hmtl file in the directory
	 * @return bool True on success
	 */
	static function CheckDir($dir,$index=true){
		global $config;

		if( !file_exists($dir) ){
			$parent = common::DirName($dir);
			gpFiles::CheckDir($parent,$index);


			//ftp mkdir
			if( isset($config['useftp']) ){
				if( !gpFiles::FTP_CheckDir($dir) ){
					return false;
				}
			}else{
				if( !@mkdir($dir,gp_chmod_dir) ){
					return false;
				}
				@chmod($dir,gp_chmod_dir); //some systems need more than just the 0755 in the mkdir() function
			}


			// make sure there's an index.html file
			// only check if we just created the directory, we don't want to keep creating an index.html file if a user deletes it
			if( $index && gp_dir_index ){
				$indexFile = $dir.'/index.html';
				if( !file_exists($indexFile) ){
					//not using gpFiles::Save() so we can avoid infinite looping (it's safe since we already know the directory exists and we're not concerned about the content)
					file_put_contents($indexFile,'<html></html>');
					@chmod($indexFile,gp_chmod_file);
				}
			}

		}


		return true;
	}

	/**
	 * Remove a directory
	 * Will only work if directory is empty
	 *
	 */
	static function RmDir($dir){
		global $config;

		//ftp
		if( isset($config['useftp']) ){
			return gpFiles::FTP_RmDir($dir);
		}
		return @rmdir($dir);
	}

	/**
	 * Remove a file or directory and it's contents
	 *
	 */
	static function RmAll($path){

		if( empty($path) ) return false;
		if( is_link($path) ) return @unlink($path);
		if( !is_dir($path) ) return @unlink($path);

		$success = true;
		$subDirs = array();
		//$files = scandir($path);
		$files = gpFiles::ReadDir($path,false);
		foreach($files as $file){
			$full_path = $path.'/'.$file;

			if( !is_link($full_path) && is_dir($full_path) ){
				$subDirs[] = $full_path;
				continue;
			}

			if( !@unlink($full_path) ){
				$success = false;
			}

		}

		foreach($subDirs as $subDir){
			if( !gpFiles::RmAll($subDir) ){
				$success = false;
			}
		}

		if( $success ){
			return gpFiles::RmDir($path);
		}
		return false;
	}


	/* FTP Function */

	static function FTP_RmDir($dir){
		$conn_id = gpFiles::FTPConnect();
		$dir = gpFiles::ftpLocation($dir);

		return ftp_rmdir($conn_id,$dir);
	}

	static function FTP_CheckDir($dir){
		$conn_id = gpFiles::FTPConnect();
		$dir = gpFiles::ftpLocation($dir);

		if( !ftp_mkdir($conn_id,$dir) ){
			return false;
		}
		return ftp_site($conn_id, 'CHMOD 0777 '. $dir );
	}

	static function FTPConnect(){
		global $config;

		static $conn_id = false;

		if( $conn_id ){
			return $conn_id;
		}

		if( empty($config['ftp_server']) ){
			return false;
		}

		$conn_id = @ftp_connect($config['ftp_server'],21,6);
		if( !$conn_id ){
			//trigger_error('ftp_connect() failed for server : '.$config['ftp_server']);
			return false;
		}

		$login_result = @ftp_login($conn_id,$config['ftp_user'],$config['ftp_pass'] );
		if( !$login_result ){
			//trigger_error('ftp_login() failed for server : '.$config['ftp_server'].' and user: '.$config['ftp_user']);
			return false;
		}
		register_shutdown_function(array('gpFiles','ftpClose'),$conn_id);
		return $conn_id;
	}

	static function ftpClose($connection=false){
		if( $connection !== false ){
			@ftp_quit($connection);
		}
	}

	static function ftpLocation(&$location){
		global $config,$dataDir;

		$len = strlen($dataDir);
		$temp = substr($location,$len);
		return $config['ftp_root'].$temp;
	}


	/**
	 * @deprecated 3.0
	 * Use gp_edit::CleanTitle() instead
	 * Used by Simple_Blog1
	 */
	static function CleanTitle($title,$spaces = '_'){
		trigger_error('Deprecated Function');
		includeFile('tool/editing.php');
		return gp_edit::CleanTitle($title,$spaces);
	}

}
