<?php
defined('is_running') or die('Not an entry point...');


/*
See also
wordpress/wp-admin/update.php
wordpress/wp-admin/includes/class-wp-upgrader.php
wordpress/wp-admin/includes/class-wp-filesystem-ftpext.php
*/

includeFile('admin/admin_tools.php');


class update_class{

	//page variables
	var $pagetype = 'update';
	var $label = 'gpEasy Updater';
	var $head = '';
	var $admin_css = '';
	var $contentBuffer = '';
	var $head_script = '';
	var $gpLayout;
	var $title = '';
	var $admin_js = false;
	var $meta_keywords = array();
	var $head_js = array();



	//for unpacking and replacing
	var $replace_dirs		= array();
	var $extra_dirs			= array();


	//update vars
	var $update_data		= array();
	var $data_timestamp		= 0;
	var $curr_step			= 1;
	var $steps				= array();
	var $core_package;
	var $update_msgs		= array();


	//content for template
	var $output_phpcheck = '';

	//force inline js and css in case for updates incase the files are deleted/changed during update processs
	var $head_force_inline = true;

	/* methods for $page usage */
	function GetContent(){
		global $langmessage;

		echo '<div id="gpx_content">';
		echo GetMessages();
		echo $this->contentBuffer;
		echo '</div>';

	}


	function __construct($process='page'){

		includeFile('tool/RemoteGet.php');
		includeFile('tool/FileSystem.php');
		$this->GetData();


		//called from admin_tools.php
		if( $process == 'embededcheck' ){
			$this->DoRemoteCheck();
			header('content-type: image/gif');
			echo base64_decode('R0lGODlhAQABAIAAAAAAAAAAACH5BAEAAAAALAAAAAABAAEAAAICRAEAOw=='); // send 1x1 transparent gif
			die();
		}


		ob_start();
		$this->Run();
		$this->contentBuffer = ob_get_clean();

	}


	function Run(){

		if( !$this->CheckPHP() ){
			echo $this->output_phpcheck;
			return;
		}

		$cmd = common::GetCommand();

		$show = true;
		switch($cmd){

			case 'checkremote':
				$this->DoRemoteCheck(true);
			break;


			case 'update';
				if( gp_remote_update && $this->Update() ){
					$show = false;
				}
			break;
		}

		if( $show ){
			$this->ShowStatus();

			echo '<h2>Status</h2>';
			$this->CheckStatus();
			echo $this->output_phpcheck;
		}

	}


	/**
	 * $update_data['packages'][id] = array()
	 *
	 *		array['id']			= id of addon (unique across all types), "core" if type is "core"
	 *		array['type']		= [core,plugin,theme]
	 *		array['md5']		= expected md5 sum of zip file
	 *		array['file']		= file on local system
	 *		array['version']	= version of the package
	 *
	 */
	function GetData(){

		$this->data_timestamp	= admin_tools::VersionData($update_data);
		$this->update_data		= $update_data;


		if( isset($this->update_data['packages']['core']) ){
			$this->core_package = $this->update_data['packages']['core'];
		}
	}


	function CheckPHP(){
		global $dataDir, $langmessage;

		ob_start();

		$passed = true;
		echo '<table class="styledtable">';

		echo '<tr><th>';
			echo $langmessage['Test'];
			echo '</th><th>';
			echo $langmessage['Value'];
			echo '</th><th>';
			echo $langmessage['Expected'];
			echo '</th></tr>';

		echo '<tr><td>';
			echo 'RemoteGet';
			echo '</td><td>';
			if( gpRemoteGet::Test() ){
				echo '<span class="passed">'.$langmessage['True'].'</span>';
			}else{
				$passed = false;
				echo '<span class="failed">'.$langmessage['False'].'</span>';
			}

			echo '</td><td>';
			echo $langmessage['True'];
			echo '</td></tr>';

		echo '<tr><td>';
			echo 'Root Installation';
			echo '</td><td>';
			if( !defined('multi_site_unique') ){
				echo '<span class="passed">'.$langmessage['True'].'</span>';
			}else{
				echo '<span class="failed">'.$langmessage['False'].'</span>';
				if( gpdebug ){
					msg('This feature is not normally available in a multi-site installation.
							It is currently accessible because gpdebug is set to true.
							Continuing is not recommended.');
				}else{
					$passed = false;
				}
			}

			echo '</td><td>';
			echo $langmessage['True'];
			echo '</td></tr>';

		echo '</table>';

		if( !$passed ){
			echo '<div class="inline_message">';
			echo $langmessage['Server_isnt_supported'];
			echo '</div>';
		}
		$this->output_phpcheck = ob_get_clean();

		return $passed;
	}

	function CheckStatus(){
		global $langmessage;

		$diff = time() - $this->data_timestamp;

		if( $this->data_timestamp > 0 ){
			echo '<p>';
			echo sprintf($langmessage['Software_updates_checked'],common::date($langmessage['strftime_datetime'],$this->data_timestamp));
			echo '</p>';
		}

		//one hour old
		if( $diff > 3600 ){
			echo '<p>';
			echo '<a href="?cmd=checkremote">'.$langmessage['Check Now'].'</a>';
			echo '</p>';
		}

	}

	function DoRemoteCheck($ForceCheck = false){
		global $langmessage;

		$diff = time() - $this->data_timestamp;

		//604800 one week
		if( !$ForceCheck && ($diff < 604800) ){
			return;
		}

		if( !$this->DoRemoteCheck2() ){
			msg($langmessage['check_remote_failed']);
		}else{
			msg($langmessage['check_remote_success']);
			$this->data_timestamp = time();
		}

		admin_tools::VersionData($this->update_data);

	}


	/**
	 * Update available package information from gpEasy.com
	 *
	 */
	function DoRemoteCheck2(){
		global $config, $dataDir;

		$path = common::IdUrl();

		//add any locally available themes with addon ids
		includeFile('tool/parse_ini.php');

		$dir = $dataDir.'/themes';
		$themes = scandir($dir);
		$theme_ids = array();
		foreach($themes as $name){
			if( $name == '.' || $name == '..' ){
				continue;
			}
			$full_dir		= $dir.'/'.$name;
			$templateFile	= $full_dir.'/template.php';
			$ini_file		= $full_dir.'/Addon.ini';

			if( !file_exists($templateFile) ){
				continue;
			}

			$ini_info = array();
			if( file_exists($ini_file) ){
				$ini_info = gp_ini::ParseFile($ini_file);
			}

			if( isset($ini_info['Addon_Unique_ID']) ){
				$theme_ids[] = $ini_info['Addon_Unique_ID'];
			}
		}
		$theme_ids  = array_unique($theme_ids );
		if( count($theme_ids) ){
			$path .= '&th='.implode('-',$theme_ids );
		}


		//get data from gpEasy
		$result = gpRemoteGet::Get_Successful($path);
		if( !$result ){
			$this->msg(gpRemoteGet::Debug('Sorry, data not fetched'));
			return false;
		}


		//zipped data possible as of gpEasy 4.1
		/*
		if( function_exists('gzinflate') ){
			$temp = gzinflate($result);
			if( $temp ){
				$result = $temp;
			}
		}
		*/

		$result = trim($result);
		$array	= json_decode($result, true); //json as of gpEasy 4.1
		if( !is_array($array) ){
			$debug						= array();
			$debug['Type']				= gettype($array);
			$debug['json_last_error']	= json_last_error();
			$debug['Two']				= substr($result,0,20);
			$this->msg(gpRemoteGet::Debug('Sorry, data not fetched',$debug));
			return false;
		}

		if( !$array ){
			$debug				= array();
			$debug['Count']		= count($array);
			$debug['Two']		= substr($result,0,20);
			$this->msg(gpRemoteGet::Debug('Sorry, data not fetched',$debug));
			return false;
		}






		$this->update_data['packages'] = array();

		$core_version = false;
		foreach($array as $info){
			$id =& $info['id'];
			if( !is_numeric($id) ){
				continue;
			}
			if( !isset($info['type']) ){
				continue;
			}
			if( $info['type'] == 'core' ){
				$id = 'core';
				$core_version = $info['version'];
			}
			$this->update_data['packages'][$id] = $info;
		}


		if( isset($this->update_data['packages']['core']) ){
			$this->core_package = $this->update_data['packages']['core'];
		}

		return true;
	}



	function ShowStatus(){
		global $langmessage;

		if( !$this->core_package ){
			return;
		}

		echo '<div class="inline_message">';
		if( version_compare(gpversion,$this->core_package['version'],'<') ){
			echo '<span class="green">';
			echo $langmessage['New_version_available'];
			echo ' &nbsp; ';
			echo '</span>';
			if( gp_remote_update ){
				echo '<a href="?cmd=update"> &#187; '.$langmessage['Update_Now'].' &#171; </a>';
			}else{
				echo 'Remote Updating is not available';
			}

			echo '<table>';
			echo '<tr>';
			echo '<td>'.$langmessage['Your_version'].'</td>';
			echo '<td>'.gpversion.'</td>';
			echo '</tr>';

			echo '<tr>';
			echo '<td>'.$langmessage['New_version'].'</td>';
			echo '<td>'.$this->core_package['version'].'</td>';
			echo '</tr>';
			echo '</table>';

		}else{
			echo $langmessage['UP_TO_DATE'];
			echo '<div>'.$langmessage['Your_version'];
			echo '  '.gpversion;
			echo '</div>';
			echo '<div>';
			echo common::link('',$langmessage['return_to_your_site']);
			echo '</div>';

			$this->RemoveUpdateMessage();
		}
		echo '</div>';

	}


	function Update(){
		global $langmessage, $gp_filesystem;


		if( !$this->core_package ){
			echo $langmessage['OOPS'].' (Core Package Not Set)';
			return false;
		}


		if( isset($_POST['step']) ){
			$this->curr_step = (int)$_POST['step'];
		}

		//already up to date?
		if( ($this->curr_step < 4) && version_compare(gpversion,$this->core_package['version'],'>=') ){
			msg($langmessage['UP_TO_DATE']);
			return false;
		}

		//filesystem
		$filesystem_method = $this->DetectFileSystem();
		if( !$filesystem_method ){
			msg('Update Aborted: Could not establish a file writing method compatible with your server.');
			return false;
		}



		$this->steps[1] = $langmessage['step:prepare'];
		$this->steps[2] = $langmessage['step:download'];
		$this->steps[3] = $langmessage['step:unpack'];
		$this->steps[4] = $langmessage['step:clean'];

		echo '<div>'.$langmessage['update_steps'].'</div>';
		echo '<ol class="steps">';
		$curr_step_label = '';
		foreach($this->steps as $temp_step => $message ){

			if( $this->curr_step == $temp_step ){
				echo '<li class="current">'.$message.'</li>';
				$curr_step_label = $message;
			}elseif( $temp_step < $this->curr_step ){
				echo '<li class="done">'.$message.'</li>';
			}else{
				echo '<li>'.$message.'</li>';
			}
		}
		echo '</ol>';

		echo '<h3>'.$curr_step_label.'</h3>';


		echo '<form method="post" action="?cmd=update">';
		if( $filesystem_method ){
			echo '<input type="hidden" name="filesystem_method" value="'.htmlspecialchars($filesystem_method).'" />';
		}

		$done		= false;
		$passed		= false;


		ob_start();
		switch($this->curr_step){
			case 4:
				$done = $this->CleanUp();
			break;
			case 3:
				$passed = $this->UnpackAndReplace();
				$this->OldFolders();
			break;
			case 2:
				$passed = $this->DownloadSource();
			break;
			case 1:
				$passed = $this->GetServerInfo();
			break;
		}
		$step_content = ob_get_clean();


		$this->OutputMessages();
		echo $step_content;


		if( $gp_filesystem ){
			$gp_filesystem->destruct();
		}
		admin_tools::VersionData($this->update_data); //save any changes made by the steps

		if( !$done ){
			if( $passed ){
				echo '<input type="hidden" name="step" value="'.min(count($this->steps),$this->curr_step+1).'"/>';
				echo '<input type="submit" class="submit" name="" value="'.htmlspecialchars($langmessage['next_step']).'" />';
			}elseif( $this->curr_step < 3 ){
				echo '<input type="hidden" name="step" value="'.min(count($this->steps),$this->curr_step).'"/>';
				echo '<input type="submit" class="submit" name="" value="'.htmlspecialchars($langmessage['continue']).'" />';
			}else{
				echo '<input type="hidden" name="failed_install" value="failed_install"/>';
				echo '<input type="hidden" name="step" value="4"/>';
				echo '<input type="submit" class="submit" name="" value="'.htmlspecialchars($langmessage['step:clean']).'..." />';
			}
		}

		echo '</form>';


		return true;
	}


	/**
	 * Determine how we'll be writing the new code to the server (ftp or direct)
	 *
	 */
	function DetectFileSystem(){
		global $dataDir, $gp_filesystem;


		//already determined
		if( isset($_POST['filesystem_method']) && gp_filesystem_base::set_method($_POST['filesystem_method']) ){
			return $_POST['filesystem_method'];
		}


		$this->curr_step = 1; //make sure we don't attempt anything beyond step 1


		$context[$dataDir]					= 'file';	// Need to be able to write to the dataDir
		$context[$dataDir . '/include']		= 'file';	// Need to be able to rename or delete the include directory
		$context[$dataDir . '/themes']		= 'dir';	// These may have user content in them and should not be completely replaced
		$context[$dataDir . '/addons']		= 'dir';
		gp_filesystem_base::init($context,'list');


		if( !$gp_filesystem ){
			return false;
		}

		return $gp_filesystem->method;
	}


	/**
	 * Remove folders and files that are no longer needed
	 *
	 */
	function CleanUp(){
		global $langmessage;


		//delete old folders
		if( isset($_POST['old_folder']) && is_array($_POST['old_folder']) ){
			$this->CleanUpFolders($_POST['old_folders']);
		}


		//failed install message
		if( isset($_POST['failed_install']) ){
			$this->msg($langmessage['settings_restored']);

			echo '<h3>';
			echo common::link('',$langmessage['return_to_your_site']);
			echo ' &nbsp; &nbsp; ';
			echo '<a href="?cmd=update">'.$langmessage['try_again'].'</a>';
			echo '</h3>';
			return true;
		}


		//delete zip file
		if( !empty($this->core_package['file']) && file_exists($this->core_package['file']) ){
			unlink($this->core_package['file']);
		}

		$this->msg($langmessage['settings_restored']);
		$this->msg($langmessage['software_updated']);


		echo '<h3>';
		echo common::link('','&#187; '.$langmessage['return_to_your_site']);
		echo '</h3>';


		return true;
	}


	/**
	 * Delete folders
	 *
	 */
	function CleanUpFolders($folders){
		global $gp_filesystem, $langmessage;

		if( $gp_filesystem->connect() !== true ){
			$this->msg($langmessage['OOPS'].' (not connected)');
			return false;
		}


		$filesystem_base	= $gp_filesystem->get_base_dir();
		$not_deleted		= array();

		foreach($folders as $old_folder){

			if( ( strpos($old_folder,'../') !== false )
				|| ( strpos($old_folder,'./') !== false )
				){
				continue;
			}

			$old_folder			= '/'.ltrim($old_folder,'/');
			$old_folder_full	= $filesystem_base.$old_folder;

			if( !$gp_filesystem->file_exists($old_folder_full) ){
				continue;
			}

			if( !$gp_filesystem->rmdir_all($old_folder_full) ){
				$not_deleted[] = htmlspecialchars($old_folder);
			}
		}

		if( count($not_deleted) > 0 ){
			$this->msg($langmessage['delete_incomplete'].': '.implode(', ',$not_deleted));
			return false;
		}

		return true;
	}


	/**
	 * Remove configuration setting that indicates gpEasy is being updated
	 *
	 */
	function RemoveUpdateMessage(){
		global $config, $langmessage;

		if( !isset($config['updating_message']) ){
			return true;
		}

		unset($config['updating_message']);
		if( !admin_tools::SaveConfig() ){
			msg($langmessage['error_updating_settings']);
			return false;
		}

		return true;
	}


	/**
	 * Replace the /include, /themes and /addons folders
	 * Start by creating the new folders with the new content
	 * Then replace the existing directories with the new directories
	 *
	 */
	function UnpackAndReplace(){
		global $langmessage, $config, $gp_filesystem, $dataDir;

		if( $gp_filesystem->connect() !== true ){
			$this->msg($langmessage['OOPS'].': (not connected)');
			return false;
		}

		if( !$this->UnpackAndSort($this->core_package['file']) ){
			return false;
		}

		$this->msg('Files Sorted');

		$config['updating_message'] = $langmessage['sorry_currently_updating'];
		if( !admin_tools::SaveConfig() ){
			$this->msg($langmessage['error_updating_settings']);
			return false;
		}

		$replaced = $gp_filesystem->ReplaceDirs( $this->replace_dirs, $this->extra_dirs );

		if( $replaced !== true ){
			$this->msg($langmessage['error_unpacking'].' '.$replaced);
			$this->RemoveUpdateMessage();
			return false;
		}

		$this->msg($langmessage['copied_new_files']);

		$this->RemoveUpdateMessage();

		return true;
	}

	/**
	 * Show which files will be deleted in the cleanup
	 *
	 */
	function OldFolders(){
		global $langmessage, $dataDir, $gp_filesystem;

		$dirs = array_merge( array_values($this->replace_dirs), array_values($this->extra_dirs));
		$dirs = array_unique( $dirs );
		if( count($dirs) == 0 ){
			return;
		}

		$filesystem_base = $gp_filesystem->get_base_dir();

		ob_start();
		echo $langmessage['old_folders_created'];

		echo '<ul>';
		foreach($dirs as $folder){

			$folder = '/'.ltrim($folder,'/');
			$folder_full = $filesystem_base.$folder;


			if( !$gp_filesystem->file_exists($folder_full) ){
				continue;
			}

			echo '<div><label>';
			echo '<input type="checkbox" name="old_folder[]" value="'.htmlspecialchars($folder).'" checked="checked" />';
			echo htmlspecialchars($folder);
			echo '</label></div>';
		}
		echo '</ul>';

		$this->msg(ob_get_clean());
	}




	/**
	 * Unpack the archive and save the files in temporary folders
	 * @return bool
	 */
	function UnpackAndSort($file){
		global $langmessage, $gp_filesystem;


		//create archive object of $file
		includeFile('thirdparty/pclzip-2-8-2/pclzip.lib.php');
		$archive = new PclZip($file);
		$archive_root = $this->ArchiveRoot( $archive );
		if( !$archive_root ){
			$this->msg($langmessage['error_unpacking'].' (no root)');
			return false;
		}
		$archive_root_len = strlen($archive_root);



		//requires a lot of memory, most likely not this much
		@ini_set('memory_limit', '256M');
		$archive_files = $archive->extract(PCLZIP_OPT_EXTRACT_AS_STRING);
		if( $archive_files == false ){
			$this->msg($langmessage['OOPS'].': '.$archive->errorInfo(true));
			return false;
		}

		$this->msg($langmessage['package_unpacked']);

		//organize
		foreach($archive_files as $file){


			if( strpos($file['filename'],$archive_root) === false ){
				continue;
			}

			$rel_filename	= substr($file['filename'],$archive_root_len);
			$name_parts		= explode('/',trim($rel_filename,'/'));
			$dir			= array_shift($name_parts);
			$replace_dir	= false;

			switch($dir){

				case 'include':
					$replace_dir	= 'include';
					$rel_filename	= implode('/',$name_parts);
				break;

				case 'themes':
				case 'addons':
					if( count($name_parts) == 0 ){
						continue 2;
					}
					$replace_dir	= $dir.'/'.array_shift($name_parts);
					$rel_filename	= implode('/',$name_parts);
				break;
			}

			if( !$replace_dir ){
				continue;
			}

			$replace_dir = trim($replace_dir,'/');
			if( isset( $this->replace_dirs[$replace_dir] ) ){
				$new_relative = $this->replace_dirs[$replace_dir];

			}else{

				$new_relative = $gp_filesystem->TempFile( $replace_dir );
				$this->replace_dirs[$replace_dir] = $new_relative;
			}

			$file_rel = $new_relative.'/'.$rel_filename;
			if( !$this->PutFile( $file_rel, $file ) ){
				return false;
			}
		}

		return true;
	}


	function PutFile( $dest_rel, $file ){
		global $langmessage, $gp_filesystem;

		$full = $gp_filesystem->get_base_dir().'/'.trim($dest_rel,'/');

		if( $file['folder'] ){
			if( !$gp_filesystem->mkdir($full) ){
				$this->msg($langmessage['error_unpacking'].' (1)');
				trigger_error('Could not create directory: '.$full);
				return false;
			}
			return true;
		}

		if( !$gp_filesystem->put_contents($full,$file['content']) ){
			trigger_error('Could not create file: '.$full);
			$this->msg($langmessage['error_unpacking'].' (2)');
			return true;
		}

		return true;
	}



	/**
	 * Find $archive_root by finding Addon.ini
	 *
	 */
	function ArchiveRoot( $archive ){

		$archive_files = $archive->listContent();
		$archive_root = false;

		//find $archive_root by finding Addon.ini
		foreach( $archive_files as $file ){

			$filename =& $file['filename'];
			if( strpos($filename,'/Addon.ini') === false ){
				continue;
			}

			$root = common::DirName($filename);

			if( !$archive_root || ( strlen($root) < strlen($archive_root) ) ){
				$archive_root = $root;
			}

		}
		return $archive_root;
	}


	/**
	 * Download the source code from gpeasy
	 *
	 */
	function DownloadSource(){
		global $langmessage;

		$this->msg('Downloading version '.$this->core_package['version'].' from gpEasy.com.');

		/* for testing
		 * $download = 'http://test.gpeasy.com/gpEasy_test.zip';
		 * $download = 'http://gpeasy.loc/x_gpEasy.zip';
		 */


		$download 		= addon_browse_path.'/Special_gpEasy?cmd=download';
		$contents		= gpRemoteGet::Get_Successful($download);

		if( !$contents || empty($contents) ){
			$this->msg($langmessage['download_failed'].'(1)');
			return false;
		}
		$this->msg($langmessage['package_downloaded']);

		$md5 = md5($contents);
		if( $md5 != $this->core_package['md5'] ){
			$this->msg($langmessage['download_failed_md5'].'<br/>Downloaded Checksum ('.$md5.') != Expected Checksum ('.$this->core_package['md5'].')');
			return false;
		}


		$this->msg($langmessage['download_verified']);


		//save contents
		$tempfile = $this->tempfile();
		if( !gpFiles::Save($tempfile,$contents) ){
			$this->msg($langmessage['download_failed'].' (2)');
			return false;
		}

		$this->core_package['file'] = $tempfile;
		return true;
	}


	/**
	 * Make sure we actuallly have the ability to write to the server using
	 *
	 */
	function GetServerInfo(){
		global $langmessage,$gp_filesystem;

		$connect_result = $gp_filesystem->connect();
		if( $connect_result === true ){

			$this->DoRemoteCheck2(); //make sure we have the latest information
			$this->msg($langmessage['ready_for_upgrade']);
			return true;

		}

		if( isset($_POST['connect_values_submitted']) ){
			msg($connect_result);
		}

		//not connected, show form
		echo '<table class="formtable">';

		$gp_filesystem->connectForm();

		echo '</table>';
		return false;

	}


	function tempfile(){
		global $dataDir;

		do{
			$tempfile = $dataDir.'/data/_temp/'.rand(1000,9000).'.zip';
		}while(file_exists($tempfile));

		return $tempfile;
	}

	/**
	 * Add an update message
	 *
	 */
	function msg($msg){
		$this->update_msgs[] = $msg;
	}

	function OutputMessages(){

		if( !$this->update_msgs ){
			return;
		}

		echo '<ul class="progress">';
		foreach($this->update_msgs as $msg){
			echo '<li>'.$msg.'</li>';
		}
		echo '</ul>';
	}
}

