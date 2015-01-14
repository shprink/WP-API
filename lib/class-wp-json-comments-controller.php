<?php

/**
 * Access comments
 */
class WP_JSON_Comments_Controller extends WP_JSON_Controller {

	/**
	 * Get a list of comments
	 *
	 * @param WP_JSON_Request $request
	 * @return array|WP_Error
	 */
	public function get_items( $request ) {
		$args = array(
			'number'  => absint( $request['per_page'] ),
			'post_id' => $request['post_id'] ? absint( $request['post_id'] ) : '',
			'user_id' => $request['user_id'] ? absint( $request['user_id'] ) : '',
			'status'  => sanitize_key( $request['status'] ),
		);

		$args['offset'] = $args['number'] * ( absint( $request['page'] ) - 1 );

		$query = new WP_Comment_Query;
		$comments = $query->query( $args );

		foreach ( $comments as &$comment ) {
			$post = get_post( $comment->comment_post_ID );
			if ( ! $this->check_read_post_permission( $post ) || ! $this->check_read_permission( $comment ) ) {

				continue;
			}

			$comment = $this->prepare_item_for_response( $comment, $request );
		}

		return $comments;
	}

	/**
	 * Get a comment.
	 *
	 * @param WP_JSON_Request $request
	 * @return array|WP_Error
	 */
	public function get_item( $request ) {
		$id = (int) $request['id'];

		$comment = get_comment( $id );
		if ( empty( $comment ) ) {
			return new WP_Error( 'json_comment_invalid_id', __( 'Invalid comment ID.' ), array( 'status' => 404 ) );
		}

		if ( ! $this->check_read_permission( $comment ) ) {
			return new WP_Error( 'json_user_cannot_read', __( 'Sorry, you cannot read this comment.' ), array( 'status' => 401 ) );
		}

		$post = get_post( $comment->comment_post_ID );
		if ( empty( $post ) ) {
			return new WP_Error( 'json_post_invalid_id', __( 'Invalid post ID.' ), array( 'status' => 404 ) );
		}

		if ( ! $this->check_read_post_permission( $post ) ) {
			return new WP_Error( 'json_user_cannot_read', __( 'Sorry, you cannot read this post.' ), array( 'status' => 401 ) );
		}

		return $this->prepare_item_for_response( $comment, $request );
	}

	/**
	 * Create a comment
	 *
	 * @param WP_JSON_Request $request Full details about the request
	 * @return array|WP_Error
	 */
	public function create_item( $request ) {
		$args = array(
			'comment_post_ID'      => (int) $request['post_id'],
			'comment_author'       => isset( $request['author'] ) ? sanitize_text_field( $request['author'] ) : '',
			'comment_author_email' => isset( $request['author_email'] ) ? sanitize_email( $request['author_email'] ) : '',
			'comment_author_url'   => isset( $request['author_url'] ) ? esc_url_raw( $request['author_url'] ) : '',
			'comment_author_IP'    => isset( $request['author_ip'] ) ? $request['author_ip'] : '',
			'comment_content'      => isset( $request['content'] ) ? $request['content'] : '',
			'comment_parent'       => isset( $request['parent_id'] ) ? (int) $request['parent_id'] : 0,
			'user_id'              => isset( $request['user_id'] ) ? (int) $request['user_id'] : get_current_user_id(),
		);

		$post = get_post( $args['comment_post_ID'] );
		if ( empty( $post ) ) {
			return new WP_Error( 'json_post_invalid_id', __( 'Invalid post ID.' ), array( 'status' => 404 ) );
		}

		if ( ! $this->check_read_post_permission( $post ) ) {
			return new WP_Error( 'json_user_cannot_read', __( 'Sorry, you cannot read this post.' ), array( 'status' => 401 ) );
		}

		$comment_id = wp_insert_comment( $args );

		if ( ! $comment_id ) {
			return new WP_Error( 'json_comment_failed_create', __( 'Creating comment failed.' ) );
		}

		$response = $this->get_item( array(
			'id'      => $comment_id,
			'context' => 'edit',
		));

		$response = json_ensure_response( $response );
		$response->set_status( 201 );
		$response->header( 'Location', json_url( '/wp/comments/' . $comment_id ) );
	}
	/**
	 * Prepare a single comment output for response
	 *
	 * @param obj $item Comment object
	 * @param obj $request Request object
	 */
	public function prepare_item_for_response( $comment, $request ) {
		$fields = array(
			'id'      => (int) $comment->comment_ID,
			'post_id' => (int) $comment->comment_post_ID,
		);

		$links = array();

		$context = isset( $request['context'] ) ? $request['context'] : '';

		// Content
		$fields['content'] = array(
			'rendered' => apply_filters( 'comment_text', $comment->comment_content, $comment )
		);
		// $fields['content']['raw'] = $comment->comment_content;

		// Status
		switch ( $comment->comment_approved ) {
			case 'hold':
			case '0':
				$fields['status'] = 'hold';
				break;

			case 'approve':
			case '1':
				$fields['status'] = 'approved';
				break;

			case 'spam':
			case 'trash':
			default:
				$fields['status'] = $comment->comment_approved;
				break;
		}

		// Type
		$fields['type'] = apply_filters( 'get_comment_type', $comment->comment_type );

		if ( empty( $fields['type'] ) ) {
			$fields['type'] = 'comment';
		}

		// Parent
		if ( (int) $comment->comment_parent ) {
			$links['parent'] = array(
				'href' => json_url( '/wp/comments/' . $comment->comment_parent )
			);
		}

		// Author
		if ( 0 !== (int) $comment->user_id ) {
			$links['author'] = array(
				'href' => json_url( '/wp/users/' . $comment->user_id )
			);
		} else {
			// to do handle comment with no auther user
		}

		// Date
		$fields['date']     = json_mysql_to_rfc3339( $comment->comment_date );
		$fields['date_gmt'] = json_mysql_to_rfc3339( $comment->comment_date_gmt );

		if ( 0 !== (int) $comment->comment_parent ) {
			$links['in-reply-to'] = array(
				'href' => json_url( sprintf( '/wp/comments/%d', (int) $comment->comment_parent ) ),
			);
		}

		$fields['_links'] = $links;

		return apply_filters( 'json_prepare_comment', $fields, $comment, $request );
	}


	/**
	 * Check if we can read a post.
	 *
	 * Correctly handles posts with the inherit status.
	 *
	 * @param object $comment Comment object
	 * @return bool Can we read it?
	 */
	protected function check_read_post_permission( $post ) {
		$posts_controller = new WP_JSON_Posts_Controller;

		return $posts_controller->check_read_permission( $post );
	}

	/**
	 * Check if we can read a comment.
	 *
	 * @param object $comment Comment object
	 * @return bool Can we read it?
	 */
	protected function check_read_permission( $comment ) {
		if ( 1 == $comment->comment_approved ) {
			return true;
		}

		if ( get_current_user_id() == $comment->user_id ) {
			return true;
		}

		return false;
	}
}
