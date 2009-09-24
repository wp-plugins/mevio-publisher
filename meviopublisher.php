<?php
/*
Plugin Name: MEVIOPublisher
Plugin URI: http://producers.mevio.com/software/mevio-publisher-wordpress-plugin/
Description: Cross publish from mevio.com to your Wordpress blog and automatically insert an episode media player.
Author: Mevio, Inc
Version: 0.3
Author URI: http://producers.mevio.com
License: GPL
*/ 

/*  

This program is free software; you can redistribute it and/or modify
it under the terms of the GNU General Public License as published by
the Free Software Foundation; either version 2 of the License, or
(at your option) any later version.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
GNU General Public License for more details.

You should have received a copy of the GNU General Public License
along with this program; if not, write to the Free Software
Foundation, Inc., 51 Franklin St, Fifth Floor, Boston, MA  02110-1301  USA
*/
define ('MEVIOPUBLISHER_VERSION', '0.3 Beta');

/**
 * Behavior switch for Services_JSON::decode()
 */
define('SERVICES_JSON_LOOSE_TYPE', 16);

//require_once('includes/simplepie.inc');
if (!class_exists('mevio_publisher')) {
    class MevioPublisher { //extends SimplePie {
		
		/**
		* @var string   The name the options are saved under in the database.
		*/
		var $adminOptionsName = "mevio_publisher_options";
		
		
		/**
		* @var string   For reporting errors, messages, etc.
		*/
		var $adminMessage = "";
		
		
		/**
		* @var array Stores the array of settings and options
		*/
		var $adminOptions = array();
		
		
		/**
		* @var bool   Flag to control when/if to ping so we do not flood ping servers.
		*/
		var $letmeping = false;
		

		
		/**
		* PHP 4 Compatible Constructor
		*/
		function meviopublisher(){$this->__construct();}
		
		/**
		* PHP 5 Constructor
		*/		
		function __construct(){

			add_action("admin_menu", array(&$this,"add_admin_pages"));
			$this->adminOptions = $this->getAdminOptions();
			
			/*
			* Register the shortcode
			*/
			if ( function_exists( 'add_shortcode' ) ) {
				add_shortcode('meviopub', array( &$this , 'meviopub_shortcode_handler' ) );
			}
			
			// look for an update post and go get the feed
			if ( isset( $_POST['update'] ) ) {
				// if we don't have the subdomain, there's no point in doing anything
				if ( $this->adminOptions['meviopub_subdomain'] ) {
					// here we need to understand whether this is the first run or not
					if (  $this->adminOptions['meviopub_firstrun'] === '1' && isset( $_POST['previmport'] ) ) {
						// this is the first run, and so we need to tell the plugin the date/time for future reference
						// in case the user has limited the number of import items.
						$this->adminOptions['meviopub_showid'] = $this->updateFromFeed($_POST['previmport']);
						$firstrun = true;
					} else { 
						$this->adminOptions['meviopub_showid'] = $this->updateFromFeed();
						$firstrun = false; 
					}
				} else {
					$this->adminMessage = 'Update failed because you have not completed your options below.';
				}
			}
		}
		
		
		/**
		 * Get the mevio RSS feed and look for new episodes to syndicate
		 * @param int Number of episodes to fetch - only set on the very first update run
		 * @return int Show ID extracted from the media URL (from the guid)
		 */
		function updateFromFeed($episodes=false) {	

			// check for correct option values, otherwise kick us out:
			if ( !$this->adminOptions['meviopub_subdomain'] ) {
				exit('Error: cannot process RSS feed as you have not entered your mevio.com domain name. Please complete the MEVIOpublisher settings.');
			}
			
			$feed = 'http://'.$this->adminOptions['meviopub_subdomain'].'.mevio.com?format=json';
						
			// Go do the work...
			$file = $this->fetch_remote_file($feed);
			if ( $file ) {
				if ( $this->adminOptions['meviopub_firstrun'] === '1' ) { $this->adminOptions['meviopub_firstrun'] = 0; }
				
				// PHP4 compatibility
				if ( !function_exists( 'json_decode' ) ) {
					require_once( dirname(__FILE__) . '/JSON.php' );
				}
				set_time_limit(60);			
				$rss = json_decode($file,SERVICES_JSON_LOOSE_TYPE);
				// loop through and deal with the data
				// get a list of all posts in the show publishing category
				
				// so we can report on how many posts were written
				$writtencount = 0;
				
				// get a list of all posts that this plugin has already inserted
				// each one has a custom field of creator=>MEVIOpublisher
				global $wpdb;
				$ct = $wpdb->query("SELECT ID, guid FROM $wpdb->posts a WHERE EXISTS (SELECT meta_id FROM $wpdb->postmeta WHERE post_id = a.ID AND meta_key LIKE '%creator%')");//in ( 'creator'))");
				$postlist = $wpdb->last_result;
				if ( $postlist ) {
					foreach ( $postlist as $k=>$p ) {
						$ps[$p->guid] = $p->ID;
					} 
				}
					$post = new StdClass;
					$cnt = 1;
					
					// get the wp settings for timezone here so we only call it once
					$tz = (get_option('gmt_offset')*3600);

					$postlist = ''; // string containing a list of posts that have been saved - gets returned
					// loop through all episodes from the rss feed
					/*
						Episode ($ep)
						[pub_date]			= Date published - unix timestamp
						[show_id]			= ID of show
						[show_name]			= Name of show
						[media][media_url]	= URL to media file
						[media][id]			= Media ID for widget
						[link]				= episode page link
						[desc]				= show notes complete
						[name]				= episode title
						[guid] - does not exist in this format
					*/
					foreach ( $rss['show']['items'] as $ep ) {
								// build our own format guid because our data feed does not have one
								// use the legacy format of the media URL
								$ep['guid'] = $ep['media']['media_url'];
								
						// logic to handle first run episode count filtering
						// and future first run date filtering
						// break after initial run episode count reached
						if ( $episodes && $cnt > $episodes ) { break; } // $episodes is false except the initial run
						// first run
						elseif ( $episodes && $cnt === 1) { $this->adminOptions['meviopub_importhistory'] = $ep['pub_date']; }
						// normal run
						elseif ( !$episodes && $this->adminOptions['meviopub_importhistory'] && strtotime($this->adminOptions['meviopub_importhistory']) >= $ep['pub_date']) { break; }
						
						$cnt++;
						
						if ( isset( $ps[$ep['guid']] ) ) { continue; }
						else {
							// if there's no guid match, then we have a new episode to past
							
							// If the flag has been set to include the direct media doanload link:
							if ( $this->adminOptions['meviopub_medialink'] ) {
								$ep['desc'] .= '<p class="meviopub_downloadink"><a href="'.$ep['media']['media_url'].'">Direct Download</a></p>';
							}
							$ep['desc'] .= '<p class="showepisode"><a href="'.$rss['show']['owner'][0]['profile_url'].'">'.$rss['show']['owner'][0]['name'].'</a> : <a href="'.$ep['link'].'">'.$ep['name'].'</a></p>';
							// If the flag has been set to include the show's alub art:
							if ( $this->adminOptions['meviopub_useart'] && isset($rss->image['url']) ) {
								$art = '<img src="'.$rss->image['url'].'" class="mpubart" ';
								if ( isset($rss->image['title']) ) $art .= 'alt="'.$rss->image['title'].' at mevio.com" ';
								$art .= '/>';
								if ( isset($rss->image['link']) ) $art = '<a href="'.$rss->image['link'].'">'.$art.'</a>';
								$ep['desc'] = $art.'<br />'.$ep['desc'];
							}
								$post->post_author = $this->adminOptions['meviopub_wpauthor'];
								$post->post_name = $ep['name'];
								$post->post_title = $ep['name'];
								$post->post_content = '<!--[meviomedia='.$ep['show_id'].'|'.$ep['media']['id'].'|'.$this->adminOptions['meviopub_videosize'].']-->';
								$post->post_content .= '<div class="mevio_shownotes">'.$ep['desc'].'</div>';
								$post->post_category = array($this->adminOptions['meviopub_wpcategory']);
								
								$poststamp = $ep['pub_date'];
								$post->post_date_gmt = date( 'Y-m-d H:i:s',$poststamp );
								// localize post_date based on this blog's timezone gtml offset
								$post->post_date     = date( 'Y-m-d H:i:s',($poststamp+$tz) );
								
								
								$post->post_status = 'publish';
								$this->insert_new( $post, $ep['guid'] );
								$postlist .= '<br />Posting episode: '.$ep['name'].' - '.$post->post_date;
								$writtencount++;
						}
						// just to be absolutely sure everything is up to date:
						$this->saveAdminOptions();
					} // END foreach
					
					if ( $writtencount > 0 ) {
						$this->adminMessage  = $writtencount;
						$this->adminMessage .= $writtencount == 1? ' episode was':' episodes were';
						$this->adminMessage .= ' syndicated from the feed.<br />';
						$this->adminMessage .= $postlist;
					} else {
						$this->adminMessage .= 'No episodes were syndicated from your feed.';
					}
				if ( !$this->adminMessage ) { $this->adminMessage .= 'No episodes were syndicated from your feed.'; }
				return $rss['show']['id'];
			}
			else { echo "There has been an error fetching your RSS feed. Please check your configuration and try again in a few minutes."; }
		}

		
		/**
		* Retrieves the options from the database.
		* @return array
		*/
		function getAdminOptions() {
		$adminOptions = array(	"meviopub_subdomain" => "",
								"meviopub_itunespingurl" => "",
								"meviopub_itunesping" => "0",
								"meviopub_medialink" => "1",
								"meviopub_wpauthor" => '1',
								"meviopub_showid" => "",
								"meviopub_wpcategory" => "1",
								"meviopub_firstrun" => '1',
								"meviopub_useart" => '',
								"meviopub_importhistory" => '0',
								"meviopub_rundate" => '',
								"meviopub_useping" => '0',
								"meviopub_pingkey" => '0',
								"meviopub_videosize" => '320x195');
		$savedOptions = get_option($this->adminOptionsName);
		if (!empty($savedOptions)) {
			foreach ($savedOptions as $key => $option) {
				$adminOptions[$key] = $option;
			}
		}
		update_option($this->adminOptionsName, $adminOptions);
		return $adminOptions;
		}
		
		/**
		* Saves the admin options to the database.
		*/
		function saveAdminOptions(){
			update_option($this->adminOptionsName, $this->adminOptions);
		}
		
		/**
		 * Adds the settings page to the admin interface
		 */
		function add_admin_pages(){
				add_submenu_page('options-general.php', "MEVIO Publisher", "MEVIO Publisher", 10, "MEVIOPublisher", array(&$this,"output_sub_admin_page_0"));
		}
		
		/**
		* Outputs the HTML for the admin sub page.
		*/
		function output_sub_admin_page_0(){
			if ( isset( $_POST['action'] ) && 'save' == $_POST['action'] ) {
			
					unset( $_POST['action'] );
					foreach ( $this->adminOptions as $k=>$v ) { 
						if ( isset( $_POST[$k] ) ) $this->adminOptions[$k] = attribute_escape($_POST[$k]);
					}
					// handle the checkboxes manually in case they need clearing
					if ( !isset($_POST['meviopub_medialink']) ) 		$this->adminOptions['meviopub_medialink'] 		= '0';
					if ( !isset($_POST['meviopub_useart']) )    		$this->adminOptions['meviopub_useart']    		= '0';
					if ( !isset($_POST['meviopub_videosize']) )    		$this->adminOptions['meviopub_videosize']    	= 'small';
					if ( !isset($_POST['meviopub_useping']) )    		{ $this->adminOptions['meviopub_useping']    	  	= '0'; $this->adminOptions['meviopub_pingkey'] = '0'; }
					if ( isset($_POST['meviopub_useping']) && $this->adminOptions['meviopub_pingkey'] == '0' ) { 
						$this->adminOptions['meviopub_pingkey'] = $this->_ranstring(6);
					}
					$this->saveAdminOptions();
			}
			?>
			<div class="wrap">
				<h2>MEVIO Publisher - version <?php echo MEVIOPUBLISHER_VERSION; ?></h2>
			<?php if ( $this->adminMessage ) { ?>
				<div id="message" class="updated fade below-h2" style="background-color: rgb(255, 251, 204);">
					<p><?php echo $this->adminMessage; ?></p>
				</div>
			<?php } ?>
			<p>Mevio Publisher is a new Wordpress Plugin that publishes your show's episodes, as they air, as posts to your Blog. The episodes will play in a widget player and the episode notes will appear in the Blog body, fully formatted.</p>
			<p>In the Beta version of this Plugin, publishing to your Blog requires a manual step:</p>
			<p>- Navigate to a custom ping URL from any browser,<br />
			&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;OR<br />
			- Hit the Update button below</p>
			<p>In the release version of this plugin, comming soon, this manual step will not be required and episodes will be published automatically as they air on the network.</p>
			
			
			<?php if ( $this->adminOptions['meviopub_firstrun'] === '1' && $this->adminOptions['meviopub_subdomain'] ) { 
				echo '<p><strong>You have not run the update for the first time.<br />Please click "Update" and wait until the process is complete.</strong></p>';
			} ?>
				
			<?php if ( $this->adminOptions['meviopub_subdomain'] ){// && function_exists('json_decode') ) { ?>
				<hr />
				
				<?php
					echo '<p style="width:200px;float:right;"><strong>Related Mevio Links</strong><br />';
					echo '<a href="http://',$this->adminOptions['meviopub_subdomain'],'.mevio.com">Show profile page</a><br />';
					if ( $this->adminOptions['meviopub_showid'] ) {  
						 echo '<a href="http://www.mevio.com/myshows/?mode=edit_about&show_id=',$this->adminOptions['meviopub_showid'],'">Edit Show profile page</a><br />';
						 echo '<a href="http://www.mevio.com/myshows/?mode=original_new_episode&show_id=',$this->adminOptions['meviopub_showid'],'">Publish new episode</a><br />';
						 echo '<a href="http://www.mevio.com/shows/edit.php?mode=current&show_id=',$this->adminOptions['meviopub_showid'],'">Previous Episode List</a><br />';
					}
					echo '</p>';
				?>
				<h3>Update your Blog with a new Show Episode</h3>
				
				<form id="meviopub_update" method="post" class="mpform">
				
			<?php if ( $this->adminOptions['meviopub_firstrun'] === '1' && $this->adminOptions['meviopub_subdomain'] ) { ?>
			<p>
			This is your first Update. <br />How many previous episodes should I import?  <select style="width:80px;" id="previmport" name="previmport">
				<option value="0" selected="selected">All</option>
				<?php
					for ( $i = 1; $i <= 20; $i++ )
					{
					    echo '<option value="',$i,'">',$i,'</option>';
					}
					
				?>
			</select>
			<br /><em>(Only valid for your first Update, subsequent Updates will import all new episodes)</em></p>

		<?php	}  ?>
				<span class="submit">
					<input type="submit" name="update" value="Update" />
					<br /><br />
					<?php if ( $this->adminOptions['meviopub_useping'] ) { ?>
						OR<br /><br/>
						<label for="inputid">Navigate to: <em><?php bloginfo('url'); ?>/?ping=mevio&reF=<?php echo $this->adminOptions['meviopub_pingkey']; ?></em><br /><br />
						</label>
					<?php } ?>
				</span>
				</form>
			
			<?php } else { ?>
				<h3>To use this plugin, please complete the Configuration below and press "Save Settings".</h3>
			<?php } ?>
			<hr style="clear:right;" />
			<form id="meviopub_optionform" method="post" class="mpform">
				<h3>Configuration</h3>
			<table class="form-table">
				<tr>
					<th scope="row" valign="top">Show subdomain</th>
					<td>
						<input type="text" id="meviopub_subdomain" name="meviopub_subdomain" value="<?php echo $this->adminOptions['meviopub_subdomain']; ?>" />
						<label for="inputid">.mevio.com<br />This is the show subdomain that you set when you created the show.</label>

					</td>
				</tr>
			</table>

			<table class="form-table">
			<tr>

					<th scope="row" valign="top">Player size</th>
					<td>
					<select  id="meviopub_videosize" name="meviopub_videosize"> 
							 <option value="medium"><?php echo attribute_escape(__('Select Media Player Size')); ?></option> 
							 <option value="small"<?php if ( 'small' == $this->adminOptions['meviopub_videosize'] ) echo ' selected="selected"'; ?>><?php echo attribute_escape(__('320 x 195 pixels')); ?></option> 
							 <option value="medium"<?php if ( 'medium' == $this->adminOptions['meviopub_videosize'] ) echo ' selected="selected"'; ?>><?php echo attribute_escape(__('420 x 235 pixels')); ?></option>
							 <option value="large"<?php if ( 'large' == $this->adminOptions['meviopub_videosize'] ) echo ' selected="selected"'; ?>><?php echo attribute_escape(__('600 x 336 pixels')); ?></option>

						</select> 
						<label for="meviopub_videosize"	><br />Select the preferred size for the embeded media player. <br />Changing this value will not affect already imported episodes.</label>
					</td>
				</tr>
				<tr>
					<th scope="row" valign="top">Direct media download link</th>
					<td>
						<input  type="checkbox" id="meviopub_medialink" name="meviopub_medialink" value="1" <?php if ( $this->adminOptions['meviopub_medialink'] == '1' ) echo ' checked'; ?> />
						<label for="meviopub_medialink">Includes a direct link to the original media file.</label>
					</td>
				</tr><tr>
					<th scope="row" valign="top">Album art</th>
					<td>
						<input  type="checkbox" id="meviopub_useart" name="meviopub_useart" value="1" <?php if ( $this->adminOptions['meviopub_useart'] == '1' ) echo ' checked'; ?> />
						<label for="meviopub_useart">Includes your show's album art (150x150 pixels) in the episode notes. <br />Can be styled using css class "mpubart".</label>
					</td>
				</tr>
				<tr>
					<th scope="row" valign="top">Wordpress category</th>
					<td>
						<select  id="meviopub_wpcategory" name="meviopub_wpcategory"> 
							 <option value=""><?php echo attribute_escape(__('Select Category')); ?></option> 
							 <?php 
							  $categories =  get_categories(array('hide_empty'=>false)); 
							  foreach ($categories as $cat) {
								$option = '<option value="'.$cat->term_id.'"';
								if ( $cat->term_id == $this->adminOptions['meviopub_wpcategory'] ) $option .= ' selected="selected"';
								$option .= '>'.$cat->cat_name;
								$option .= '</option>';
								echo $option;
							  }
							 ?>
						</select>
						<label for="meviopub_wpcategory"><br />Select a Wordpress Category for your episode posts. <br /><strong>If you change this category later, you risk re-importing older episodes!</strong></label>
					</td>
				</tr>
				<tr>
					<th scope="row" valign="top">Default author</th>
					<td>
						<select  id="meviopub_wpauthor" name="meviopub_wpauthor"> 
							 <option value=""><?php echo attribute_escape(__('Select a default author')); ?></option> 
							 <?php 
							  $authors =  $this->list_authors(); 
							  foreach ($authors as $id=>$a) {
								$option = '<option value="'.$id.'"';
								if ( $id == $this->adminOptions['meviopub_wpauthor'] ) $option .= ' selected="selected"';
								$option .= '>'.$a;
								$option .= '</option>';
								echo $option;
							  }
							 ?>
						</select><br />
						<label for="meviopub_wpauthor"	>Select a Wordpress Author to assign to imported episode posts.</label>
					</td>
				</tr>
				
				<tr>
					<th scope="row" valign="top">Activate Ping URL</th>
					<td>
						<input  type="checkbox" id="meviopub_useping" name="meviopub_useping" value="1"<?php if ( $this->adminOptions['meviopub_useping'] == '1' ) echo ' checked'; ?> />
						<label for="meviopub_useping">Activate Ping URL<br />The Ping URL is a simple mechanism to generate an update in a Wordpress Blog without loggin in to the Admin. <br />
						To RESET, deactivate the Ping URL option, Save settings, then re-Activate this option. <br />
						The active Ping URL is displayed next to the Update button above.
						</label>
					</td>
				</tr>
				
				</table>
				<input type="hidden" id="action" name="action" value="save" />
				<p>
				<span class="submit">
					<input type="submit" value="Save Settings" />
				</span>
				</p>
			</form>
			</div>
			<?php
		} 
		
		
		
		/**
		* meviopub_shortcode_handler - produces and returns the content to replace the shortcode tag
		*
		* @param array $atts  An array of attributes passed from the shortcode
		* @param string $content   If the shortcode wraps round some html, this will be passed.
		*/
		function meviopub_shortcode_handler( $atts , $content = null) {
			//add the attributes you want to accept to the array below
			$attributes = shortcode_atts(array(
		      'attr_1' => 'attribute 1 default',
		      'attr_2' => 'attribute 2 default',
		      // ...etc
			), $atts);
		
			//create the content you want to replace the shortcode in the post, here.
		
			//return the content. DO NOT USE ECHO.
			return 'default return value from meviopub';
		}
		

		/**
		 * Update and set a settings/options value
		 * @param mixed
		 * @param string
		 */
		function setSettingsValue($value,$type='options')
		{
			if ( $type == 'options' ) 
			{
				return get_settings( $value['id'] ) != "" ? get_settings( $value['id'] ) : $value['std']; 
			}
			elseif ( $type == 'pluginoptions' ) 
			{
				return  isset( $this->channelTagOptions[$value['id']]['value'] ) && $this->channelTagOptions[$value['id']]['value'] != "" ? $this->channelTagOptions[$value['id']]['value'] : $value['std'];
			}
		}
		
		
		/**
		 * For fauto form creation layout **DEPRECIATED**
		 * @param array
		 */
		function option_wrapper_header($values){
		?>
		<tr valign="top"> 
			<th scope="row"><?php echo $values['name']; ?>:</th>
			<td>
		<?php
		}
		
		
		/**
		 * For fauto form creation layout **DEPRECIATED**
		 * @param array
		 */
		function option_wrapper_footer($values){
			print '</td></tr>';
			if ( $values['desc'] != '' ) {
			?>
					<tr valign="top">
						<td>&nbsp;</td><td valign="top"><small><?php echo $values['desc']; ?></small></td>
						</tr>
			<?php			
			}
		}
		
		/**
		 * Save the entered option values
		 * @param array
		 */
		function saveeChannelOptions(& $options)
		{
			    $shortname = $this->shortname;

				//if ( $_GET['page'] == basename(__FILE__) ) {

					if ( 'save' == $_POST['action'] ) {
		
							foreach ($options as $value) {
								if($value['type'] != 'multicheck'){
									if ( isset( $value['vartype'] ) && $value['vartype'] == 'int' ) {
										$uval =(int)$_POST[ $value['id'] ];
									}else{
										$uval = self::strip_generic($_POST[ $value['id'] ]);
									}
									update_option( $value['id'],  $uval); 
								}else{
									foreach($value['options'] as $mc_key => $mc_value){
										$up_opt = $value['id'].'_'.$mc_key;
										update_option($up_opt, self::strip_generic($_POST[$up_opt]));
									}
								}
							}
			
							foreach ($options as $value) {
								if($value['type'] != 'multicheck'){
									if ( isset( $value['vartype'] ) && $value['vartype'] == 'int' ) {
										$uval =(int)$_POST[ $value['id'] ];
									}else{
										$uval = self::strip_generic($_POST[ $value['id'] ]);
									}
									if( isset( $_POST[ $value['id'] ] ) ) { update_option( $value['id'], self::strip_generic($_POST[ $value['id'] ])  ); } else { delete_option( $value['id'] ); } 
								}else{
									foreach($value['options'] as $mc_key => $mc_value){
										$up_opt = $value['id'].'_'.$mc_key;						
										if( isset( $_POST[ $up_opt ] ) ) { update_option( $up_opt, self::strip_generic($_POST[ $up_opt ])  ); } else { delete_option( $up_opt ); } 
									}
								}
							}
							//header("Location: themes.php?page=functions.php&saved=true");
							//die;
			
					} else if( 'reset' == $_POST['action'] ) {
			
						foreach ($options as $value) {
							if($value['type'] != 'multicheck'){
								delete_option( $value['id'] ); 
							}else{
								foreach($value['options'] as $mc_key => $mc_value){
									$del_opt = $value['id'].'_'.$mc_key;
									delete_option($del_opt);
								}
							}
						}
					}
					
		} // END save ChannelOptions
		
			function use_api ($tag) {
				global $wp_db_version;
				if ('wp_insert_post'==$tag) :
					// Before 2.2, wp_insert_post does too much of the wrong stuff to use it
					// In 1.5 it was such a resource hog it would make PHP segfault on big updates
					$ret = (isset($wp_db_version) and $wp_db_version > FWP_SCHEMA_21);
				endif;
				return $ret;		
			} // function SyndicatedPost::use_api ()
		
		

		/**
		 * Write a new post to the database, and manually update the post's guid
		 * so we can match with the mevio guid.
		 * Sourced from feedwordpress pluging
		 * @param array Post data to write
		 * @param string Guid to manually insert
		 */
		function insert_new ($post,$guid) {
			global $wpdb, $wp_db_version;
			// Why doesn't wp_insert_post already do this?
			foreach ($post as $key => $value) :
				if (is_string($value)) :
					$dbpost[$key] = $wpdb->escape($value);
				else :
					$dbpost[$key] = $value;
				endif;
			endforeach;
				$dbpost['post_pingback'] = false; // Tell WP 2.1 and 2.2 not to process for pingbacks
				// this call, without the "@" generates an internal error
				// The "@" prevents the warning from displaying
				// A little hack, but currently no way around it
				$wp_id = @wp_insert_post($dbpost);
				$dbpost['id'] = $wp_id;
				$dbpost['meta']['enclosure'] = $guid;
				$dbpost['meta']['creator'] = 'MEVIOpublisher';
				$this->add_rss_meta($dbpost);
				$this->adminOptions['meviopub_showid'] = $this->guidExtraction($guid);
				
				// This should never happen.
				if (!is_numeric($wp_id) or ($wp_id == 0)) :
					MevioPublisher::critical_bug('Insert_New (wp_id problem)', $this, __LINE__);
				endif;
		
				// Unfortunately, as of WordPress 2.3, wp_insert_post()
				// *still* offers no way to use a guid of your choice,
				// and munges your post modified timestamp, too.
				$result = $wpdb->query("
					UPDATE $wpdb->posts
					SET guid='{$guid}'
					WHERE ID='{$wp_id}'
				");
				
				// Wordpress 2.8 has a problem associating new posts with the appropriate category
				//  this is a hack workaround till they get it fixed
				
		} /* MevioPublisher::insert_new() */
		
		
		// SyndicatedPost::add_rss_meta: adds interesting meta-data to each entry
		// using the space for custom keys. The set of keys and values to add is
		// specified by the keys and values of $post['meta']. This is used to
		// store anything that the WordPress user might want to access from a
		// template concerning the post's original source that isn't provided
		// for by standard WP meta-data (i.e., any interesting data about the
		// syndicated post other than author, title, timestamp, categories, and
		// guid). It's also used to hook into WordPress's support for
		// enclosures.
		function add_rss_meta ($post) {
			global $wpdb;
			if ( is_array($post) and isset($post['meta']) and is_array($post['meta']) ) :
				$postId = $post['id'];
				
				// Aggregated posts should NOT send out pingbacks.
				// WordPress 2.1-2.2 claim you can tell them not to
				// using $post_pingback, but they don't listen, so we
				// make sure here.
				$result = $wpdb->query("
				DELETE FROM $wpdb->postmeta
				WHERE post_id='$postId' AND meta_key='_pingme'
				");
				foreach ( $post['meta'] as $key => $values ) :
	
					// If this is an update, clear out the old
					// values to avoid duplication.
					$result = $wpdb->query("
					DELETE FROM $wpdb->postmeta
					WHERE post_id='$postId' AND meta_key='$key'
					");
	
					// Allow for either a single value or an array
					if (!is_array($values)) $values = array($values);
					foreach ( $values as $value ) :
						$value = $wpdb->escape($value);
						$result = $wpdb->query("
						INSERT INTO $wpdb->postmeta
						SET
							post_id='$postId',
							meta_key='$key',
							meta_value='$value'
						");
					endforeach;
				endforeach;
			endif;
		} /* SyndicatedPost::add_rss_meta () */


		/**
		 * Some simple anti XSS stuff
		 * @param string
		 */
		function strip_generic($s)
		{
			return preg_replace('/[^a-z0-9~\.:_-\s\/]/i','',$s);
		}
		
		
		/**
		 * Grab a list of all blog authors so the user can assign all episode posts to them.
		 * Copied from feedwordpress
		 */
		function list_authors () {
			global $wpdb;
			$ret = array();
			$users = $wpdb->get_results("SELECT * FROM $wpdb->users ORDER BY display_name");
			if (is_array($users)) :
				foreach ($users as $user) :
					$id = (int) $user->ID;
					$ret[$id] = $user->display_name;
				endforeach;
			endif;
			return $ret;
		}
		
		/**
		 * Push some additional admininterface styles into the settings
		 */
		 function add_adminstyles() {
			$url = get_settings('siteurl');
			$url = $url . '/wp-content/plugins/mevio-publisher/admin.css';
			echo '<link rel="stylesheet" type="text/css" href="' . $url . '" />';

		 }
		 
		# Internal debugging functions
		function critical_bug ($varname, $var, $line) {
				global $wp_version;
		
				echo '<p>There may be a bug in MEVIOpublisher. Please contact the author and paste the following information into your e-mail:</p>';
				echo "\n<plaintext>";
				echo "Triggered at line # ".$line."\n";
				echo "MEVIOpublisher version: ".MEVIOPUBLISHER_VERSION."\n";
				echo "WordPress version: $wp_version\n";
				echo "PHP version: ".phpversion()."\n";
				echo "\n";
				echo $varname.": "; var_dump($var); echo "\n";
				die;
		}
		
		
		/** 
		 * Extract some information from the guid/episode media URL
		 * @param string The mevio.com media guid/URL to process
		 * @param string What to retrieve:
		 *        'episodeid' : episode ID
		 *        'showid'    : The show id (default)
		 */
		function guidExtraction($guid,$item='showid') {
			$arr = explode('/',$guid);
			switch($item) {
				case'showid': return $arr[4]; break;
				case'episodeid': return $arr[6]; break;
				case'mediafile': return $arr[7]; break;
			}
		}
		
		
		/**
		 * Generate a random string
		 * @param int Number of characters in returned string
		 * @return string
		 */
		 function _ranstring($length=6)
		{
			$characters = '0123456789abcdefghijklmnopqrstuvwxyz';
			$string ='';    
			for ($p = 0; $p < $length; $p++) {
				$string .= $characters[mt_rand(0, strlen($characters))];
			}
			return $string;
		}
		
		
	
		function fetch_remote_file($file) {
			// use curl
			if ( function_exists( 'curl_init' ) ) {
				$curl_handle=curl_init();
				curl_setopt($curl_handle,CURLOPT_URL,$file);
				curl_setopt($curl_handle,CURLOPT_CONNECTTIMEOUT,2);
				curl_setopt($curl_handle,CURLOPT_RETURNTRANSFER,1);
				$buffer = curl_exec($curl_handle);
				curl_close($curl_handle);
				
				if (empty($buffer)) { return false; }
				else { return $buffer; }
				
			} elseif ( function_exists( 'file_get_contents') ) { 
				$content = file_get_contents($file);
				return $content? $content : false;
			} else  { return false; }
		}
		
		
		
	} // class MevioPublisher
}

//instantiate the class
if (class_exists('MevioPublisher')) {
	$mevio_publisher = new MevioPublisher();
}

/**
 * Look for an incoming mevio ping request and update from the feed if necessary.
 * We handle it this way so we can add a xmlrpc ping handler function in the future
 * by looking for a different $_GET['ping'] value while retaining legacy URL pings 
 */
function ping_meviopub() {
	if ( isset( $_GET['ping'] ) && isset( $_GET['reF'] ) && $_GET['ping'] == 'mevio' ) {
		if (is_home()) {
			global $mevio_publisher;
			// chck the validity of the requested ping URL
			if ( $_GET['reF'] !== $mevio_publisher->adminOptions['meviopub_pingkey'] ) {
				header('Location:'.get_bloginfo('url'));
				exit();
			}
			$mevio_publisher->updateFromFeed();
			exit($mevio_publisher->adminMessage);
		}		
	}
	
	
	
} 

/**
 * Handle auto insertion of embed player on the fly, so we can avoid any code stripping, and also means 
 * the user can turn players on and off site-wide at any time in the future.

 */
 
 
function mv_generate_tags($showid, $episodeid, $width, $height, $poster = "", $autoplay = "false", $controller = "") {
			$tag  = '<!-- start insertion of MEVIO media player -->';
			$tag .= '<div class="mevio_player">';
			$tag .= '<object classid="clsid:d27cdb6e-ae6d-11cf-96b8-444553540000"codebase="http://download.macromedia.com/pub/shockwave/cabs/flash/swflash.cab#version=9,0,0,0" width="'.$width.'" height="'.$height.'" id="MevioWM" align="middle"><param name="allowScriptAccess" value="never" /><param name="allowFullScreen" value="true" /><param name="movie" value="http://www.mevio.com/widgets/mwm/MevioWM.swf" /><param name="quality" value="high" /><param name="FlashVars"     value="distribConfig=http://www.mevio.com/widgets/configFiles/distribconfig_mwm_pcw_default.xml&autoPlay=false&container=false&rssFeed=/%3FsId='.$showid.'%26sMediaId='.$episodeid.'%26format=json&playerIdleEnabled=false" /><param name="bgcolor" value="#000000" />	<embed src="http://www.mevio.com/widgets/mwm/MevioWM.swf"quality="high"bgcolor="#000000"width="'.$width.'" height="'.$height.'" FlashVars="distribConfig=http://www.mevio.com/widgets/configFiles/distribconfig_mwm_pcw_default.xml&autoPlay=false&6container=false&rssFeed=/%3FsId='.$showid.'%26sMediaId='.$episodeid.'%26format=json&playerIdleEnabled=false"name="MevioWM"align="middle"allowScriptAccess="never"allowFullScreen="true"type="application/x-shockwave-flash"pluginspage="http://www.macromedia.com/go/getflashplayer" /></object>';
			$tag .= '</div>';
			$tag .= '<!-- end Mevio media -->';
    		return $tag;
}

function mv_meviopub_post($the_content) {
	global $mevio_publisher;
	if ( strstr( $the_content, '[meviomedia=' ) !== FALSE ) {
	preg_match_all("/\[meviomedia=([0-9]*)\|([0-9]*)\|([a-zA-Z0-9]*)\]/", $the_content, $matches, PREG_SET_ORDER); // match all podshow references and sort them into a nice array

	foreach($matches as $match) { // Go through matches and replace them	
			switch ($match[3]) {
				case'small':	$w='320'; $h='195'; break;
				case'medium':	$w='420'; $h='235'; break;
				case'large':	$w='600'; $h='336'; break;
				default: 		$w='320'; $h='195'; break;	
			}
			$the_content = preg_replace("/\<!--\[meviomedia=([0-9]*)\|([0-9]*)\|([a-zA-Z0-9]*)\]-->/", mv_generate_tags($match[1], $match[2],$w,$h), $the_content, 1);
		}
	}
    return $the_content;
}



// add them actions...
add_filter('the_content', 'mv_meviopub_post');
add_filter('the_excerpt','mv_meviopub_post');
if ( $mevio_publisher->adminOptions['meviopub_useping'] ) { add_action('get_header','ping_meviopub',0);	}

