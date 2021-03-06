<?php
/**
 * Extension Import function. You will need to modify this function slightly ensure all values are added to the database.
 * Please see the section below on how to do this.
 */

//replace 'feed' with the 'name' of your extension, as defined in your config.php file.
function bebop_rss_import( $extension, $user_metas = null ) {
	global $wpdb, $bp;
	
	$itemCounter = 0;
	
	if ( empty( $extension ) ) {
		bebop_tables::log_general( 'Importer', 'The $extension parameter is empty.' );
		return false;
	}
	else {
		$this_extension = bebop_extensions::bebop_get_extension_config_by_name( $extension );
	}
	require_once (ABSPATH . WPINC . '/class-feed.php');
	
	//if user_metas is not defined, get some user meta.
	if( ! isset( $user_metas ) ) {
		$user_metas = bebop_tables::get_user_ids_from_meta_type( $this_extension['name'] );
	}
	else {
		$secondary_importers = true;
	}

	if ( isset( $user_metas ) ) {
		foreach ( $user_metas as $user_meta ) {
			//Ensure the user is wanting to import items.
			if ( bebop_tables::get_user_meta_value( $user_meta->user_id, 'bebop_' . $this_extension['name'] . '_active_for_user' ) ) {
				
				if ( isset( $secondary_importers ) && $secondary_importers === true ) {
					$feeds = bebop_tables::get_initial_import_feeds( $user_meta->user_id , $this_extension['name'] );
					$user_feeds = bebop_tables::get_user_feeds_from_array( $user_meta->user_id , $this_extension['name'], $feeds );
				}
				else {
					$user_feeds = bebop_tables::get_user_feeds( $user_meta->user_id , $this_extension['name'] );
				}
				foreach ($user_feeds as $user_feed ) {
					$errors = null;
					$items 	= null;
					
					$feed_name = $user_feed->meta_name;
					$feed_url = $user_feed->meta_value;
					$import_username = stripslashes( $feed_name );
					
					//Check the user has not gone past their import limit for the day.
					if ( ! bebop_filters::import_limit_reached( $this_extension['name'], $user_meta->user_id, $import_username ) ) {
						
						if ( bebop_tables::check_for_first_import( $user_meta->user_id, $this_extension['name'], 'bebop_' . $this_extension['name'] . '_' . $import_username . '_do_initial_import' ) ) {
							bebop_tables::delete_from_first_importers( $user_meta->user_id, $this_extension['name'], 'bebop_' . $this_extension['name'] . '_' . $import_username . '_do_initial_import' );
						}
						
						/* 
						 * ******************************************************************************************************************
						 * Depending on the data source, you will need to switch how the data is retrieved. If the feed is RSS, use the 	*
						 * SimplePie method, as shown in the youtube extension. If the feed is oAuth API based, use the oAuth implementation*
						 * as shown in the twitter extension. If the feed is an API without oAuth authentication, use SlideShare			*
						 * ******************************************************************************************************************
						 */
						
						//import the url
						//Configure the feed
						$feed = new SimplePie();
						$feed->set_feed_url( $feed_url );
						$feed->set_cache_class( 'WP_Feed_Cache' );
						$feed->set_file_class( 'WP_SimplePie_File' );
						$feed->enable_cache( false );
						$feed->set_cache_duration( 0 );
						do_action_ref_array( 'wp_feed_options', array( $feed, $feed_url ) );
						$feed->init();
						$feed->handle_content_type();
						
						/* 
						 * ******************************************************************************************************************
						 * We can get as far as loading the items, but you will need to adjust the values of the variables below to match 	*
						 * the values from the extension's feed.																			*
						 * This is because each feed return data under different parameter names, and the simplest way to get around this is*
						 * to quickly match the values. To find out what values you should be using, consult the provider's documentation.	*
						 * You can also contact us if you get stuck - details are in the 'support' section of the admin homepage.			*
						 * ******************************************************************************************************************
						 * 
						 * Values you will need to check and update are:
						 * 		$errors 				- Must point to the error boolean value (true/false)
						 * 		$id						- Must be the ID of the item returned through the data feed.
						 * 		$description			- The actual content of the imported item.
						 * 		$item_published			- The time the item was published.
						 * 		$action_link			- This is where the link will point to - i.e. where the user can click to get more info.
						 */
						 
						 
						//Edit the following variables to point to where the relevant content is being stored in the feed:
						$errors	 = $feed->error;
						
						if ( ! $errors ) {
							
							//Edit the following variable to point to where the relevant content is being stored in the feed:
							$items = $feed->get_items();
							if ( $items ) {
								foreach ( $items as $item ) {
									if ( ! bebop_filters::import_limit_reached( $this_extension['name'], $user_meta->user_id, $import_username ) ) {
										
										//Edit the following variables to point to where the relevant content is being stored:
										//$description	= $item->get_content();
										$item_published = date( 'Y-m-d H:i:s', strtotime( $item->get_date() ) );
										$title			= $item->get_title();
										$action_link	= $item->get_permalink();
										
										$id_array = array_reverse( explode( '/', $action_link ) ); 
										$id = $id_array[1];
										//Stop editing - you should be all done.
										
										
										//generate an $item_id
										$item_id = bebop_generate_secondary_id( $user_meta->user_id, $id, $item_published );
										
										//if the id is not found, import the content.
										if ( ! bebop_tables::check_existing_content_id( $user_meta->user_id, $this_extension['name'], $item_id ) ) {
											
											$item_content = $title . '
											' . $action_link;
											
											if ( bebop_create_buffer_item(
															array(
																'user_id' 			=> $user_meta->user_id,
																'extension' 		=> $this_extension['name'],
																'type' 				=> $this_extension['content_type'],
																'username'			=> $import_username,							//required for day counter increases.
																'content' 			=> $item_content,
																'content_oembed' 	=> $this_extension['content_oembed'],
																'item_id' 			=> $item_id,
																'raw_date' 			=> $item_published,
																'actionlink'	 	=> $action_link,
															)
											) ) {
												$itemCounter++;
											}
										}//End if ( ! empty( $secondary->secondary_item_id ) ) {
									}
									unset($item);
								}
							}
						}
						else {
							bebop_tables::log_error( sprintf( __( 'Importer - %1$s', 'bebop' ), $this_extension['display_name'] ), sprintf( __( 'RSS Error: %1$s', 'bebop' ), $errors ) );
						}
					}
				}//End foreach ($user_feeds as $user_feed ) {
				unset($user_meta);
			}
		}
	}
	//return the result
	return $itemCounter . ' ' . $this_extension['content_type'] . 's';
}
