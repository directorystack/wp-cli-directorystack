<?php // phpcs:ignore WordPress.Files.FileName
/**
 * Manage DirectoryStack users through the commands line.
 *
 * @package   directorystack
 * @author    Sematico LTD <hello@sematico.com>
 * @copyright 2020 Sematico LTD
 * @license   https://www.gnu.org/licenses/gpl-3.0.html GPL-3.0-or-later
 * @link      https://directorystack.com
 */

namespace DirectoryStackCLI;

use WP_CLI;

// Exit if accessed directly
defined( 'ABSPATH' ) || exit;

/**
 * Handles users.
 */
class Users extends DirectoryStackCommand {

	/**
	 * Generate random users for testing purposes.
	 *
	 * ## OPTIONS
	 *
	 * [--number=<number>]
	 * : Number of users to generate.
	 *
	 * [--key=<key>]
	 * : uifaces.co api key to retrieve avatars.
	 *
	 * @param array $args arguments.
	 * @param array $assoc_args arguments.
	 * @return void
	 */
	public function generate( $args, $assoc_args ) {

		if ( is_multisite() ) {
			WP_CLI::error( 'Multisite is not supported!' );
		}

		$r = wp_parse_args(
			$assoc_args,
			array(
				'number' => 30,
				'key'    => false,
			)
		);

		$number = absint( $r['number'] );
		$key    = $r['key'];

		if ( ! $key ) {
			WP_CLI::error( 'No api key provided.' );
		}

		$avatars = $this->get_avatars( $number, $key );

		$notify = \WP_CLI\Utils\make_progress_bar( "Generating $number users(s)", $number );

		foreach ( range( 0, $number ) as $i ) {
			$notify->tick();
			$this->register_user( $avatars );
		}

		$notify->finish();

		WP_CLI::success( 'Done.' );

	}

	/**
	 * Create a random user.
	 *
	 * @param array $avatars list of avatars found from the api.
	 * @return void
	 */
	private function register_user( $avatars = array() ) {

		$faker = \Faker\Factory::create();

		$password = wp_generate_password( 12, false );
		$username = $faker->userName;
		$email    = $faker->safeEmail;

		$create_user = wp_create_user( $username, $password, $email );

		if ( ! is_wp_error( $create_user ) ) {

			$random_avatar = \Faker\Provider\Base::randomElements( $avatars, 1 );
			$avatar        = false;

			if ( isset( $random_avatar[0] ) && ! empty( $random_avatar[0] ) ) {
				$avatar = ds_rest_upload_image_from_url( $random_avatar[0] );
			}

			if ( is_array( $avatar ) ) {
				$att_id = ds_rest_set_uploaded_image_as_attachment( $avatar );
				if ( $att_id ) {
					update_user_meta( $create_user, 'user_avatar', $att_id );
				}
			}

			wp_update_user(
				array(
					'ID'         => $create_user,
					'first_name' => $faker->firstName,
					'last_name'  => $faker->lastName,
				)
			);
		}

	}

	/**
	 * Get avatars from the api.
	 *
	 * @param integer $number the number of avatars to load.
	 * @param string  $key the api key.
	 * @return array
	 */
	private function get_avatars( $number = 30, $key ) {

		$avatars = array();

		$query = wp_remote_get(
			'https://uifaces.co/api?limit=' . $number,
			array(
				'headers' => array(
					'X-API-KEY'     => $key,
					'Accept'        => 'application/json',
					'Cache-Control' => 'no-cache',
				),
			)
		);

		$response = json_decode( wp_remote_retrieve_body( $query ) );

		if ( ! empty( $response ) && is_array( $response ) ) {
			foreach ( $response as $profile ) {
				$avatars[] = esc_url( $profile->photo );
			}
		}

		return $avatars;

	}

	/**
	 * Generate custom fields data for all users.
	 *
	 * @return void
	 */
	public function generate_data() {

		// WP_User_Query arguments
		$args = array(
			'number' => -1,
			'fields' => array( 'id' ),
		);

		// The User Query
		$user_query = new \WP_User_Query( $args );

		$number = count( $user_query->get_results() );

		$faker = \Faker\Factory::create();

		$notify = \WP_CLI\Utils\make_progress_bar( "Generating $number users(s)", $number );

		$custom_fields = ( new \DirectoryStack\Models\UserField() )
			->where( 'default_field', '=', null )
			->findAll()
			->get();

		foreach ( $user_query->get_results() as $user ) {

			$id = $user->id;

			foreach ( $custom_fields as $field ) {
				switch ( $field->type ) {
					case 'url':
						$text = 'https://example.com';
						update_user_meta( $id, $field->metakey, $text );
						break;
					case 'email':
						$text = $faker->safeEmail;
						update_user_meta( $id, $field->metakey, $text );
						break;
					case 'text':
						$text = \Faker\Provider\Lorem::sentence( 10, true );
						update_user_meta( $id, $field->metakey, $text );
						break;
					case 'editor':
					case 'textarea':
						$text = \Faker\Provider\Lorem::paragraphs( 2, true );
						update_user_meta( $id, $field->metakey, $text );
						break;
					case 'multiselect':
					case 'multicheckbox':
						$options = $field->get_setting( 'selectable_options', array() );
						$options = array_rand( $options, 2 );
						update_user_meta( $id, $field->metakey, $options );
						break;
					case 'radio':
					case 'select':
						$options = $field->get_setting( 'selectable_options', array() );
						update_user_meta( $id, $field->metakey, key( array_slice( $options, 1, 1, true ) ) );
						break;
					case 'checkbox':
						update_user_meta( $id, $field->metakey, true );
						break;
					case 'number':
						update_user_meta( $id, $field->metakey, \Faker\Provider\Base::randomNumber() );
						break;
				}
			}

			$notify->tick();

		}

		$notify->finish();

		WP_CLI::success( 'Done.' );

	}

}
