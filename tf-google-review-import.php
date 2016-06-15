<?php
/******************************************************************
Plugin Name:       Tenfold Google Review Import
Plugin URI:        https://tenfold.media
Description:       Imports Tenfold Media Google Reviews and stores them as a custom post type
Author:            Tim Rye
Author URI:        https://tenfold.media/tim
Version:           1.1.0
GitHub Plugin URI: TenfoldMedia/tf-google-review-import
GitHub Branch:     master
******************************************************************/


/**********************************************
  SET UP THE CUSTOM POST TYPE
**********************************************/

// Flush rewrite rules for custom post types
add_action('after_switch_theme', 'flush_rewrite_rules');

// let's create the function for the custom type
function custom_post_tf_testimonial() {
	register_post_type('tf_testimonial', array(
		'labels' => array(
			'name' =>'Testimonials',
			'singular_name' => 'Testimonial',
			'all_items' => 'All Testimonials',
			'add_new' => 'Add New',
			'add_new_item' => 'Add New Testimonial',
			'edit' => 'Edit',
			'edit_item' => 'Edit Testimonial',
			'new_item' => 'New Testimonial',
			'view_item' => 'View Testimonial',
			'search_items' => 'Search Testimonials',
			'not_found' =>  'No testimonials found in the database.',
			'not_found_in_trash' => 'No testimonials found in the trash',
			'parent_item_colon' => ''
		),
		'public' => false,
		'show_ui' => true,
		'hierarchical' => false,
		'rewrite'	=> array (
			'slug' => 'testimonials',
			'with_front' => false
		),
		'query_var' => 'testimonials'
	));

	register_taxonomy('testimonial_category', array('tf_testimonial'), array(
		'hierarchical' => true,
		'labels' => array(
			'name' =>                   'Categories',
			'singular_name' =>          'Testimonial Category',
			'search_items' =>           'Search Testimonial Categories',
			'all_items' =>              'All Testimonial Categories',
			'parent_item' =>            'Parent Testimonial Category',
			'parent_item_colon' =>      'Parent Testimonial Category:',
			'edit_item' =>              'Edit Testimonial Category',
			'update_item' =>            'Update Testimonial Category',
			'add_new_item' =>           'Add New Testimonial Category',
			'new_item_name' =>          'New Testimonial Category Name'
		),
		'public' =>                     false,
		'show_admin_column' =>          true,
		'show_ui' =>                    true,
		'query_var' =>                  true,
		'rewrite' =>                    array(
			'slug' => 'testimonial-category',
			'with_front' => false
		)
	));
}
add_action('init', 'custom_post_tf_testimonial');

// Add custom post types counts to dashboard
function custom_glance_items_testimonials( $items = array() ) {
    $post_types = array('tf_testimonial');
    foreach($post_types as $type) {
        if(!post_type_exists($type)) continue;
        $num_posts = wp_count_posts($type);
        if($num_posts) {
            $published = intval($num_posts->publish);
            $post_type = get_post_type_object($type);
            $text = _n('%s ' . $post_type->labels->singular_name, '%s ' . $post_type->labels->name, $published, 'your_textdomain');
            $text = sprintf($text, number_format_i18n($published));
            if (current_user_can($post_type->cap->edit_posts)) {
            $output = '<a href="edit.php?post_type=' . $post_type->name . '">' . $text . '</a>';
                echo '<li class="post-count ' . $post_type->name . '-count">' . $output . '</li>';
            } else {
            $output = '<span>' . $text . '</span>';
                echo '<li class="post-count ' . $post_type->name . '-count">' . $output . '</li>';
            }
        }
    }
    return $items;
}
add_filter('dashboard_glance_items', 'custom_glance_items_testimonials', 10, 1);


/**********************************************
  FETCH GOOGLE REVIEWS
**********************************************/

function tf_do_request($url) {
	// Send API Call using WP's HTTP API
	$data = wp_remote_get($url);

	if (is_wp_error($data)) {
		$error_message = $data->get_error_message();
		echo "Something went wrong: $error_message";
	}

	// If that failed, use CURL
	if (!is_array($data) || empty($data['body'])) {
		$ch = curl_init($url);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_HEADER, 0);
		$data = curl_exec($ch); // Google response
		curl_close($ch);
		$response = json_decode($data, true);
	}
	else { $response = json_decode($data['body'], true); }

	if (isset($response['error_message'])) { return false; }
	else { return $response; }
}

function tf_fetch_and_process_reviews() {
	$google_api_key = 'AIzaSyDpT17fnGVSAG8D9TqVV2aUPepN1yW_CYI';
	$placeid = 'ChIJDeM_6sF5dkgRBTv9KMQECZo';

	$place_reviews_url = add_query_arg(
		array(
			'placeid'   => $placeid,
			'key'       => $google_api_key
		),
		'https://maps.googleapis.com/maps/api/place/details/json'
	);

	$response = tf_do_request($place_reviews_url);

	// Get user avatar images
	if (isset($response['result']['reviews']) && !empty($response['result']['reviews'])) {

		$tf_reviews = array();

		// Loop through the reviews
		foreach ($response['result']['reviews'] as $review) {

			// Get the Google User ID
			$user_id = isset($review['author_url']) ? str_replace('https://plus.google.com/', '', $review['author_url']) : '';

			// Make the URL of the Google User's avatar image
			$image_request_url = add_query_arg(
				array('alt' => 'json'),
				'http://picasaweb.google.com/data/entry/api/user/'.$user_id
			);

			$avatar_get_body = tf_do_request($image_request_url);

			$avatar_img = $avatar_get_body['entry']['gphoto$thumbnail']['$t'];

			//add array image to review array
			$review = array_merge($review, array('avatar' => $avatar_img));

			array_push($tf_reviews, $review);
		}

		$response['result']['reviews'] = $tf_reviews;
	}

	return $response;
}

function tf_get_google_reviews() {

	// Regenerate the data
	$response = tf_fetch_and_process_reviews();

	if (!$response) { return false; }

	$reviews = $response['result']['reviews'];
	$rating = $response['result']['rating'];

	// Get the number of published testimonials (only ones shown on the testimonials page are counted)
	$review_count = 0;
	$testimonials = new WP_Query(array(
		'post_type' => 'tf_testimonial',
		'posts_per_page' => -1	//get all posts
	));
	while ($testimonials->have_posts()) {
		$testimonials->the_post();
		if (get_field('show_on_testimonials_page') && !get_field('person_works_for_tenfold_media')) $review_count++;
	}

	$expiration = 60 * 60 * 6; // 6 hrs

	// Save the transient
	set_transient('tf_google_reviews', $reviews, $expiration);
	set_transient('tf_google_rating', $rating, $expiration);
	set_transient('tf_google_review_count', $review_count, $expiration);

	// If we have reviews
	if (isset($reviews) && !empty($reviews)) {

		$unmatched_reviews = false;

		foreach ($reviews as $review) { if (!isset($review['matched']) || $review['matched'] == false) { $unmatched_reviews = true; break; } }

		if ($unmatched_reviews) {

			$testimonials = new WP_Query(array(
				'post_type' => 'tf_testimonial',
				'posts_per_page' => -1	//get all posts
			));

			// Loop through the reviews
			foreach ($reviews as $key => $review) {

				// Loop through the posts
				while ($testimonials->have_posts()) {
					$testimonials->the_post();

					$post_time_string = get_the_time('U');

					// If the review matches a post, break out of the REVIEW loop (the foreach)
					if ($review["time"] == $post_time_string) {
						$reviews[$key]["matched"] = true;
						break;
					}
				}
				$testimonials->rewind_posts();

				if (!$reviews[$key]["matched"]) {

					// No match found, must be a new testimonial from Google, so add a new post:

					$new_post_id = wp_insert_post(array(
						'post_type' => 'tf_testimonial',
						'post_status' => 'publish',
						'post_date' => date('Y-m-d H:i:s', $review["time"]),
						'post_title' => 'Google Review by '.$review["author_name"]
					));

					if (!is_wp_error($new_post_id)) {
						update_field('field_546695fc287fe', false, $new_post_id);						// show_on_testimonials_page
						update_field('field_5464d9caa770d', $review["text"], $new_post_id);				// words
						update_field('field_546532c9f4974', false, $new_post_id);						// person_works_for_tenfold_media
						update_field('field_5465333df4977', $review["author_name"], $new_post_id);		// name
						update_field('field_5465338cf497a', false, $new_post_id);						// use_google_image
						update_field('field_546533fdf497c', $review["avatar"], $new_post_id);			// image_url
						update_field('field_5465344bf497f', false, $new_post_id);						// big_photo
						update_field('field_546534c0f4981', strval($review["rating"]), $new_post_id);	// star_rating
						update_field('field_5465351ef4983', 'wide', $new_post_id);						// block_size
					}

					$reviews[$key]["matched"] = true;

					wp_mail('webmaster@tenfold.media', 'New Google Review', 'New Google Review imported to Tenfold Media WordPress site ('.site_url().'). View here: '.get_edit_post_link($new_post_id, ' '));
				}
			}

			set_transient('tf_google_reviews', $reviews, $expiration);
		}
	}
}

function we_have_this($var) {
	return $var && $var !== '';
}

function get_review_data($data_name) {
	$rating = get_transient('tf_google_rating');
	$review_count = get_transient('tf_google_review_count');

	if (!we_have_this($rating) || !we_have_this($review_count)) {
		tf_get_google_reviews();

		$rating = get_transient('tf_google_rating');
		$review_count = get_transient('tf_google_review_count');
	}

	switch ($data_name) {
		case 'rating':       return $rating;
		case 'review_count': return $review_count;
		case 'num_stars':    return round(floatval($rating) * 2) / 2;
	}
}

function the_review_data($data_name) {
	echo strval(get_review_data($data_name));
}

function get_reviews_when_in_admin_message() { ?>
    <div class="notice notice-info is-dismissable"><p>Fetching Google Reviews</p></div>
<?php }

function get_reviews_when_in_admin() {
	global $post_type;
	if ((isset($_GET['post_type']) && $_GET['post_type'] === 'tf_testimonial') || (isset($post_type) && $post_type === 'tf_testimonial')) {
		if (!get_transient('tf_google_admin_fetch')) {
			set_transient('tf_google_admin_fetch', '1', 60 * 5 /* 5 mins */);
			tf_get_google_reviews();
			add_action('admin_notices', 'get_reviews_when_in_admin_message');
		}
	}
}
add_action('admin_init', 'get_reviews_when_in_admin');
