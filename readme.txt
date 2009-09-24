=== MEVIO Publisher ===
Contributors: ndmevio
Tags: podcast,podcasting,publishing,mevio,media,video,audio
Requires at least: 2.7.1
Tested up to: 2.8.4
Stable tag: 0.3

Cross publish from your mevio.com media account to your Wordpress blog.

== Description ==

This plugin provides a straightforward way to take video and audio episodes uploaded to your mevio.com account and add them to your Wordpress blog, including show notes, media link, and automatic insertion of the mevio.com media player.

== Installation ==

1. Place the entire folder 'mevio-publisher' in your Wordpress plugins folder ( /wp-content/plugins/ )

2. Activate the plugin as normal in /wp-admin/plugins.php

3. Go to the settings page ( /wp-admin/options-general.php?page=MEVIOPublisher ) and enter your mevio.com subdomain in appropriate form field. 
e.g. if your show is mygreatshow.mevio.com, you should enter just mygreatshow in that box.

4. Configure any other settings as you require them, then click "Save Settings"

- Player Size: this configures the size of the media player automatically inserted into your posts when you post is created. Changing this does not affect previously imported posts.

- Direct media download link: this adds a hyperlink to your post that points directly to the original media file for your episode. We advise you activate this function to ensure Wordpress encloses the media file correctly in your RSS feed.

- Album Art: this setting automatically adds your show's album art into the episode description, when a post is created. It can be styled by referencing the "mpubart" css class.

- Wordpress category: select the category you want to associate with all imported episodes. If you would like a new category for your episodes, you must first create one in the normal way before you are able to select it from this dropdown. This setting works only for future Updates. You cannot change the category of previously inserted posts by changing this setting.

- Default author: choose the Wordpress author name you want associated with your episode posts

- Activate Ping URL: this setting enables the creation of a unique URL that you can enter into your browser to  trigger the "Update Now" button without logging-in to your Wordpress admin. This URL is unique, and if you turn this setting off then on again, a new, unique URL will be created for you.

5. The first time you run the plugin, you have the option to limit the number of old episodes to sync into your blog. Once you have chosen the total number, click "Update Now" and the plugin will fetch your episodes from your mevio.com account.

6. If there is an error, double check you have entered your mevio.com subdomain correctly, and check all settings before contacting the plugin's author.


== Frequently Asked Questions ==

= Can I use the MEVIO Publisher with other media hosting networks? =

This plugin is specifically configured only to work with data from shows registered and hosted at mevio.com.


= Can I upload to mevio.com from my Wordpress admin? =

This is a one-way sync FROM mevio.com TO Wordpress.


= Can I sync with my mevio.com account without loggin-in to my Wordpress Admin? =

Once you have properly configured the mevio-publisher plugin, your Wordpress MevioPublisher admin page will show you a unique "ping" URL which you can access without logging-in to your Wordpress Admin pages.

Ensure you select "Activate MEVIO Publisher ping?" in the Publishing Settings to activate this function.
Deactivating then re-activating the setting, will create a new, unique ping URL.


= What are the links at the upper right of the admin page for? =

These are merely useful shortcuts to some of your show, media and administration pages on mevio.com. These shortcuts only appear once you have entered your mevio.com subdomain, and and clicked "Save Settings" for the first time.


# == Changelog ==  
#   
# = 0.3 =  
# * Compatibility with WP2.8.x
# * Improved episode importing to avoid duplicates.
#   
# = 0.2 =  
# * Fixed font style display bug apparent on some blogs.
# * Compatibility with PHP 4.x
#   
# = 0.1 =  
# * Initial release.   