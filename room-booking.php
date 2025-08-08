<?php
/**
 * Plugin Name: TSA0C3 Room Booking
 * Description: A simple room booking system with calendar view for WordPress.
 * Version: 1.0
 * Author: Mark Lovesey
 */

// Create the "Rooms" custom post type
function srb_register_room_cpt()
{
    register_post_type('srb_room', [
        'label' => 'Rooms',
        'public' => true,
        'show_in_rest' => true,
        'has_archive' => false,
        'rewrite' => ['slug' => 'rooms'],
        'supports' => ['title'],
        'show_in_menu' => false, // Hide from main menu since we'll show in our custom menu
    ]);
}
add_action('init', 'srb_register_room_cpt');

// Create the "Bookings" custom post type  
function srb_register_booking_cpt()
{
    register_post_type('srb_booking', [
        'label' => 'Bookings',
        'public' => false,
        'show_ui' => true,
        'show_in_rest' => true,
        'has_archive' => false,
        'supports' => ['title'],
        'show_in_menu' => false, // Hide from main menu since we'll show in our custom menu
    ]);
}
add_action('init', 'srb_register_booking_cpt');

// Add room details fields to the room edit page
function srb_add_room_meta_boxes()
{
    add_meta_box(
        'srb_room_details',
        'Room Details',
        'srb_room_details_callback',
        'srb_room'
    );
}
add_action('add_meta_boxes', 'srb_add_room_meta_boxes');

// Display the room details form fields
function srb_room_details_callback($post)
{
    wp_nonce_field('srb_save_room_details', 'srb_room_nonce');
    $capacity = get_post_meta($post->ID, '_srb_capacity', true);
    $equipment = get_post_meta($post->ID, '_srb_equipment', true);

    echo '<table class="form-table">';
    echo '<tr><th><label for="srb_capacity">Capacity:</label></th>';
    echo '<td><input type="number" id="srb_capacity" name="srb_capacity" value="' . esc_attr($capacity) . '" /></td></tr>';
    echo '<tr><th><label for="srb_equipment">Equipment:</label></th>';
    echo '<td><textarea id="srb_equipment" name="srb_equipment" rows="3" cols="50">' . esc_textarea($equipment) . '</textarea></td></tr>';
    echo '</table>';
}

// Save the room details when the room is updated
function srb_save_room_details($post_id)
{
    if (!isset($_POST['srb_room_nonce']) || !wp_verify_nonce($_POST['srb_room_nonce'], 'srb_save_room_details')) {
        return;
    }

    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
        return;
    }

    if (!current_user_can('edit_post', $post_id)) {
        return;
    }

    if (isset($_POST['srb_capacity'])) {
        update_post_meta($post_id, '_srb_capacity', sanitize_text_field($_POST['srb_capacity']));
    }

    if (isset($_POST['srb_equipment'])) {
        update_post_meta($post_id, '_srb_equipment', sanitize_textarea_field($_POST['srb_equipment']));
    }
}
add_action('save_post', 'srb_save_room_details');

// Load the JavaScript and CSS files for the calendar
function srb_enqueue_scripts()
{
    wp_enqueue_script('srb-calendar', plugin_dir_url(__FILE__) . 'assets/js/calendar.js', ['jquery'], '1.0', true);
    wp_enqueue_style('srb-calendar', plugin_dir_url(__FILE__) . 'assets/css/calendar.css', [], '1.0');

    // Make AJAX URL available to JavaScript
    wp_localize_script('srb-calendar', 'srb_ajax', [
        'ajax_url' => admin_url('admin-ajax.php'),
        'nonce' => wp_create_nonce('srb_booking_nonce'),
    ]);
}
add_action('wp_enqueue_scripts', 'srb_enqueue_scripts');

// Handle AJAX request to create a new booking
function srb_create_booking()
{
    check_ajax_referer('srb_booking_nonce', 'nonce');

    $date = sanitize_text_field($_POST['date']);
    $room_id = intval($_POST['room_id']);
    $description = sanitize_textarea_field($_POST['description']);
    $time_slot = sanitize_text_field($_POST['time_slot']);
    $start_time = sanitize_text_field($_POST['start_time']);
    $end_time = sanitize_text_field($_POST['end_time']);
    $setup_required = sanitize_text_field($_POST['setup_required']);
    $setup_details = sanitize_textarea_field($_POST['setup_details']);

    // Check if this time slot conflicts with existing bookings
    $conflict = srb_check_time_overlap($date, $room_id, $start_time, $end_time);

    if ($conflict) {
        wp_send_json_error('Room is already booked during this time period.');
        return;
    }

    $booking_id = srb_create_single_booking($date, $room_id, $description, $time_slot, $start_time, $end_time, $setup_required, $setup_details);
    
    if ($booking_id) {
        wp_send_json_success('Booking created successfully.');
    } else {
        wp_send_json_error('Failed to create booking.');
    }
}
add_action('wp_ajax_srb_create_booking', 'srb_create_booking');
add_action('wp_ajax_nopriv_srb_create_booking', 'srb_create_booking');

// Create a single booking record in the database
function srb_create_single_booking($date, $room_id, $description, $time_slot, $start_time, $end_time, $setup_required = 'no', $setup_details = '')
{
    $booking_id = wp_insert_post([
        'post_type' => 'srb_booking',
        'post_title' => get_the_title($room_id) . ' - ' . $date,
        'post_status' => 'publish',
    ]);

    if ($booking_id) {
        update_post_meta($booking_id, '_srb_booking_date', $date);
        update_post_meta($booking_id, '_srb_room_id', $room_id);
        update_post_meta($booking_id, '_srb_description', $description);
        update_post_meta($booking_id, '_srb_time_slot', $time_slot);
        update_post_meta($booking_id, '_srb_start_time', $start_time);
        update_post_meta($booking_id, '_srb_end_time', $end_time);
        update_post_meta($booking_id, '_srb_setup_required', $setup_required);
        update_post_meta($booking_id, '_srb_setup_details', $setup_details);
        update_post_meta($booking_id, '_srb_user_id', get_current_user_id());

        return $booking_id;
    }

    return false;
}

// Handle AJAX request to check for booking conflicts
function srb_check_conflicts()
{
    check_ajax_referer('srb_booking_nonce', 'nonce');

    $date = sanitize_text_field($_POST['date']);
    $room_id = intval($_POST['room_id']);
    $start_time = sanitize_text_field($_POST['start_time']);
    $end_time = sanitize_text_field($_POST['end_time']);

    $conflict = srb_check_time_overlap($date, $room_id, $start_time, $end_time);

    if ($conflict) {
        $conflict_booking = get_post($conflict);
        $conflict_time_slot = get_post_meta($conflict, '_srb_time_slot', true);

        wp_send_json_success([
            'hasConflict' => true,
            'conflictDetails' => 'Room already booked from ' . $conflict_time_slot
        ]);
    } else {
        wp_send_json_success([
            'hasConflict' => false,
            'conflictDetails' => null
        ]);
    }
}
add_action('wp_ajax_srb_check_conflicts', 'srb_check_conflicts');
add_action('wp_ajax_nopriv_srb_check_conflicts', 'srb_check_conflicts');

// Complex algorithm to check if time slots overlap using 30-minute intervals
function srb_check_time_overlap_slots($date, $room_id, $new_start, $new_end)
{
    // Define time slots (30-minute intervals from 8:00 to 18:30)
    $time_slots = [];
    for ($hour = 8; $hour <= 18; $hour++) {
        $time_slots[] = sprintf("%02d:00", $hour);
        if ($hour < 18) { // Don't add :30 for 18:30+
            $time_slots[] = sprintf("%02d:30", $hour);
        }
    }

    // Convert new booking times to slot indices
    $new_start_idx = array_search($new_start, $time_slots);
    $new_end_idx = array_search($new_end, $time_slots);

    if ($new_start_idx === false || $new_end_idx === false) {
        return "Invalid time slots";
    }

    // Validate booking duration (max 8 hours = 16 slots of 30 minutes each)
    $booking_duration_slots = $new_end_idx - $new_start_idx;
    $max_slots = 16; // 8 hours * 2 (30-minute slots per hour)

    if ($booking_duration_slots > $max_slots) {
        return "Booking duration exceeds 8-hour maximum";
    }

    if ($booking_duration_slots <= 0) {
        return "End time must be after start time";
    }

    // Initialize comparison array
    $comparison = array_fill(0, count($time_slots), 0);

    // Get existing bookings
    $existing_bookings = get_posts([
        'post_type' => 'srb_booking',
        'numberposts' => -1,
        'meta_query' => [
            'relation' => 'AND',
            [
                'key' => '_srb_booking_date',
                'value' => $date,
                'compare' => '='
            ],
            [
                'key' => '_srb_room_id',
                'value' => $room_id,
                'compare' => '='
            ]
        ]
    ]);

    // Mark existing bookings in comparison array
    foreach ($existing_bookings as $booking) {
        $existing_start = get_post_meta($booking->ID, '_srb_start_time', true);
        $existing_end = get_post_meta($booking->ID, '_srb_end_time', true);

        $existing_start_idx = array_search($existing_start, $time_slots);
        $existing_end_idx = array_search($existing_end, $time_slots);

        if ($existing_start_idx !== false && $existing_end_idx !== false) {
            // Mark all slots from start to end-1 (end is exclusive)
            for ($slot_idx = $existing_start_idx; $slot_idx < $existing_end_idx; $slot_idx++) {
                $comparison[$slot_idx] += 1;
            }
        }
    }

    // Check if new booking would overlap
    for ($slot_idx = $new_start_idx; $slot_idx < $new_end_idx; $slot_idx++) {
        if ($comparison[$slot_idx] >= 1) {
            // Find which booking conflicts
            foreach ($existing_bookings as $booking) {
                $existing_start = get_post_meta($booking->ID, '_srb_start_time', true);
                $existing_end = get_post_meta($booking->ID, '_srb_end_time', true);

                $existing_start_idx = array_search($existing_start, $time_slots);
                $existing_end_idx = array_search($existing_end, $time_slots);

                if ($slot_idx >= $existing_start_idx && $slot_idx < $existing_end_idx) {
                    return $booking->ID; // Return conflicting booking ID
                }
            }
        }
    }

    return false; // No conflicts
}

function srb_check_time_overlap($date, $room_id, $new_start, $new_end)
{
    return srb_check_time_overlap_slots($date, $room_id, $new_start, $new_end);
}

// Convert time string to minutes
function srb_time_to_minutes($time_str)
{
    $time_parts = explode(':', $time_str);
    return intval($time_parts[0]) * 60 + intval($time_parts[1]);
}

// Handle AJAX request to get bookings for a specific date
function srb_get_bookings()
{
    check_ajax_referer('srb_booking_nonce', 'nonce');

    $date = sanitize_text_field($_POST['date']);

    $bookings = get_posts([
        'post_type' => 'srb_booking',
        'numberposts' => -1,
        'meta_query' => [
            [
                'key' => '_srb_booking_date',
                'value' => $date,
                'compare' => '='
            ]
        ]
    ]);

    $booking_data = [];
    foreach ($bookings as $booking) {
        $room_id = get_post_meta($booking->ID, '_srb_room_id', true);
        $booking_data[] = [
            'id' => $booking->ID,
            'room_id' => $room_id,
            'room_name' => get_the_title($room_id),
            'description' => get_post_meta($booking->ID, '_srb_description', true),
            'time_slot' => get_post_meta($booking->ID, '_srb_time_slot', true),
            'user_id' => get_post_meta($booking->ID, '_srb_user_id', true),
        ];
    }

    wp_send_json_success($booking_data);
}
add_action('wp_ajax_srb_get_bookings', 'srb_get_bookings');
add_action('wp_ajax_nopriv_srb_get_bookings', 'srb_get_bookings');

// Create the WordPress admin menu structure
function srb_admin_menu()
{
    // Add main menu page
    add_menu_page(
        'Room Bookings',           // Page title
        'Room Bookings',           // Menu title
        'manage_options',          // Capability
        'room-bookings',           // Menu slug
        'srb_admin_main_page',     // Function
        'dashicons-calendar-alt',  // Icon
        25                         // Position
    );

    // Add submenu pages (tabs)
    add_submenu_page(
        'room-bookings',           // Parent slug
        'Settings',                // Page title
        'Settings',                // Menu title
        'manage_options',          // Capability
        'room-bookings',           // Menu slug (same as parent for default)
        'srb_admin_main_page'      // Function
    );

    add_submenu_page(
        'room-bookings',           // Parent slug
        'Manage Rooms',            // Page title
        'Manage Rooms',            // Menu title
        'manage_options',          // Capability
        'room-bookings-rooms',     // Menu slug
        'srb_admin_rooms_page'     // Function
    );

    add_submenu_page(
        'room-bookings',           // Parent slug
        'All Bookings',            // Page title
        'All Bookings',            // Menu title
        'manage_options',          // Capability
        'room-bookings-bookings',  // Menu slug
        'srb_admin_bookings_page'  // Function
    );
}
add_action('admin_menu', 'srb_admin_menu');

// Main admin page with tabs
function srb_admin_main_page()
{
    $active_tab = isset($_GET['tab']) ? $_GET['tab'] : 'settings';
    ?>
    <div class="wrap">
        <h1>Room Bookings Management</h1>

        <nav class="nav-tab-wrapper">
            <a href="?page=room-bookings&tab=settings"
                class="nav-tab <?php echo $active_tab == 'settings' ? 'nav-tab-active' : ''; ?>">
                Settings
            </a>
            <a href="?page=room-bookings&tab=rooms"
                class="nav-tab <?php echo $active_tab == 'rooms' ? 'nav-tab-active' : ''; ?>">
                Manage Rooms
            </a>
            <a href="?page=room-bookings&tab=bookings"
                class="nav-tab <?php echo $active_tab == 'bookings' ? 'nav-tab-active' : ''; ?>">
                All Bookings
            </a>
        </nav>

        <div class="tab-content">
            <?php
            switch ($active_tab) {
                case 'settings':
                    srb_settings_tab();
                    break;
                case 'rooms':
                    srb_rooms_tab();
                    break;
                case 'bookings':
                    srb_bookings_tab();
                    break;
                default:
                    srb_settings_tab();
                    break;
            }
            ?>
        </div>
    </div>
    <?php
}

// Redirect submenu pages to main page with tabs
function srb_admin_rooms_page()
{
    wp_redirect(admin_url('admin.php?page=room-bookings&tab=rooms'));
    exit;
}

function srb_admin_bookings_page()
{
    wp_redirect(admin_url('admin.php?page=room-bookings&tab=bookings'));
    exit;
}

// Settings tab content
function srb_settings_tab()
{
    if (isset($_POST['submit'])) {
        $password = sanitize_text_field($_POST['access_password']);
        $confirm_password = sanitize_text_field($_POST['confirm_password']);

        // Validate passwords match
        if ($password !== $confirm_password) {
            echo '<div class="notice notice-error"><p>Passwords do not match. Please try again.</p></div>';
        } else {
            $hashed_password = wp_hash_password($password);
            update_option('srb_access_password_hash', $hashed_password);
            echo '<div class="notice notice-success"><p>Password updated successfully!</p></div>';
        }
    }
    ?>
    <div class="srb-admin-section">
        <h2>Calendar Access Settings</h2>
        <form method="post">
            <table class="form-table">
                <tr>
                    <th scope="row">Access Password</th>
                    <td>
                        <input type="password" name="access_password" class="regular-text" required>
                        <p class="description">Set the password required to access the room booking calendar</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">Confirm Password</th>
                    <td>
                        <input type="password" name="confirm_password" class="regular-text" required>
                        <p class="description">Re-enter the password to confirm</p>
                    </td>
                </tr>
            </table>
            <?php submit_button('Update Password'); ?>
        </form>

        <hr>

        <h3>Usage Instructions</h3>
        <p>Use the shortcode <code>[room_booking]</code> on any page or post to display the booking calendar.</p>

        <h3>Current Statistics</h3>
        <?php
        $total_rooms = wp_count_posts('srb_room')->publish;
        $total_bookings = wp_count_posts('srb_booking')->publish;
        $today_bookings = get_posts([
            'post_type' => 'srb_booking',
            'numberposts' => -1,
            'meta_query' => [
                [
                    'key' => '_srb_booking_date',
                    'value' => date('Y-m-d'),
                    'compare' => '='
                ]
            ]
        ]);
        ?>
        <ul>
            <li><strong>Total Rooms:</strong> <?php echo $total_rooms; ?></li>
            <li><strong>Total Bookings:</strong> <?php echo $total_bookings; ?></li>
            <li><strong>Today&rsquo;s Bookings:</strong> <?php echo count($today_bookings); ?></li>
        </ul>
    </div>
    <?php
}

// Rooms tab content
function srb_rooms_tab()
{
    // Handle room deletion
    if (isset($_GET['action']) && $_GET['action'] === 'delete' && isset($_GET['room_id'])) {
        $room_id = intval($_GET['room_id']);
        wp_delete_post($room_id, true);
        echo '<div class="notice notice-success"><p>Room deleted successfully!</p></div>';
    }

    // Get all rooms
    $rooms = get_posts([
        'post_type' => 'srb_room',
        'numberposts' => -1,
        'post_status' => 'publish'
    ]);
    ?>
    <div class="srb-admin-section">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
            <h2>Manage Rooms</h2>
            <a href="<?php echo admin_url('post-new.php?post_type=srb_room'); ?>" class="button button-primary">Add New
                Room</a>
        </div>

        <?php if (empty($rooms)): ?>
            <p>No rooms found. <a href="<?php echo admin_url('post-new.php?post_type=srb_room'); ?>">Create your first room</a>.
            </p>
        <?php else: ?>
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th>Room Name</th>
                        <th>Capacity</th>
                        <th>Equipment</th>
                        <th>Total Bookings</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($rooms as $room):
                        $capacity = get_post_meta($room->ID, '_srb_capacity', true);
                        $equipment = get_post_meta($room->ID, '_srb_equipment', true);
                        
                        // Count bookings for this room
                        $room_bookings = get_posts([
                            'post_type' => 'srb_booking',
                            'numberposts' => -1,
                            'meta_query' => [
                                [
                                    'key' => '_srb_room_id',
                                    'value' => $room->ID,
                                    'compare' => '='
                                ]
                            ]
                        ]);
                        ?>
                        <tr>
                            <td><strong><?php echo esc_html($room->post_title); ?></strong></td>
                            <td><?php echo $capacity ? esc_html($capacity) : '<em>Not set</em>'; ?></td>
                            <td><?php echo $equipment ? esc_html($equipment) : '<em>None specified</em>'; ?></td>
                            <td><?php echo count($room_bookings); ?></td>
                            <td>
                                <a href="<?php echo admin_url('post.php?post=' . $room->ID . '&action=edit'); ?>" class="button button-small">Edit</a>
                                <a href="?page=room-bookings&tab=rooms&action=delete&room_id=<?php echo $room->ID; ?>"
                                    class="button button-small button-link-delete"
                                    onclick="return confirm('Are you sure you want to delete this room? All associated bookings will be lost.')">Delete</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
    <?php
}

// Bookings tab content
function srb_bookings_tab()
{
    // Handle booking deletion
    if (isset($_GET['action']) && $_GET['action'] === 'delete' && isset($_GET['booking_id'])) {
        $booking_id = intval($_GET['booking_id']);
        wp_delete_post($booking_id, true);
        echo '<div class="notice notice-success"><p>Booking deleted successfully!</p></div>';
    }

    // Get filter parameters
    $filter_date = isset($_GET['filter_date']) ? sanitize_text_field($_GET['filter_date']) : '';
    $filter_room = isset($_GET['filter_room']) ? intval($_GET['filter_room']) : '';

    // Build query
    $meta_query = [];
    if ($filter_date) {
        $meta_query[] = [
            'key' => '_srb_booking_date',
            'value' => $filter_date,
            'compare' => '='
        ];
    }
    if ($filter_room) {
        $meta_query[] = [
            'key' => '_srb_room_id',
            'value' => $filter_room,
            'compare' => '='
        ];
    }

    $bookings = get_posts([
        'post_type' => 'srb_booking',
        'numberposts' => -1,
        'meta_query' => $meta_query,
        'orderby' => 'meta_value',
        'meta_key' => '_srb_booking_date',
        'order' => 'DESC'
    ]);

    // Get all rooms for filter
    $all_rooms = get_posts([
        'post_type' => 'srb_room',
        'numberposts' => -1,
        'post_status' => 'publish'
    ]);
    ?>
    <div class="srb-admin-section">
        <h2>All Bookings</h2>

        <!-- Filters -->
        <form method="get" style="margin-bottom: 20px; background: #f9f9f9; padding: 15px; border-radius: 5px;">
            <input type="hidden" name="page" value="room-bookings">
            <input type="hidden" name="tab" value="bookings">

            <label for="filter_date">Filter by Date:</label>
            <input type="date" id="filter_date" name="filter_date" value="<?php echo esc_attr($filter_date); ?>">

            <label for="filter_room" style="margin-left: 20px;">Filter by Room:</label>
            <select id="filter_room" name="filter_room">
                <option value="">All Rooms</option>
                <?php foreach ($all_rooms as $room): ?>
                    <option value="<?php echo $room->ID; ?>" <?php selected($filter_room, $room->ID); ?>>
                        <?php echo esc_html($room->post_title); ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <input type="submit" class="button" value="Filter" style="margin-left: 20px;">
            <a href="?page=room-bookings&tab=bookings" class="button" style="margin-left: 10px;">Clear Filters</a>
        </form>

        <?php if (empty($bookings)): ?>
            <p>No bookings found.</p>
        <?php else: ?>
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Time Slot</th>
                        <th>Room</th>
                        <th>Setup Required</th>
                        <th>Description</th>
                        <th>Booked On</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($bookings as $booking):
                        $room_id = get_post_meta($booking->ID, '_srb_room_id', true);
                        $booking_date = get_post_meta($booking->ID, '_srb_booking_date', true);
                        $time_slot = get_post_meta($booking->ID, '_srb_time_slot', true);
                        $description = get_post_meta($booking->ID, '_srb_description', true);
                        $setup_required = get_post_meta($booking->ID, '_srb_setup_required', true);
                        $setup_details = get_post_meta($booking->ID, '_srb_setup_details', true);
                        $room_name = get_the_title($room_id);
                        ?>
                        <tr>
                            <td><?php echo date('M j, Y', strtotime($booking_date)); ?></td>
                            <td><?php echo esc_html($time_slot); ?></td>
                            <td><strong><?php echo esc_html($room_name); ?></strong></td>
                            <td>
                                <span class="setup-indicator setup-<?php echo $setup_required; ?>">
                                    <?php echo $setup_required === 'yes' ? 'Yes' : 'No'; ?>
                                </span>
                                <?php if ($setup_required === 'yes' && $setup_details): ?>
                                    <br><small style="color: #666; font-style: italic;"><?php echo esc_html($setup_details); ?></small>
                                <?php endif; ?>
                            </td>
                            <td><?php echo $description ? esc_html($description) : '<em>No description</em>'; ?></td>
                            <td><?php echo date('M j, Y g:i A', strtotime($booking->post_date)); ?></td>
                            <td>
                                <a href="?page=room-bookings&tab=bookings&action=delete&booking_id=<?php echo $booking->ID; ?>"
                                    class="button button-small button-link-delete"
                                    onclick="return confirm('Are you sure you want to delete this booking?')">Delete</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <p style="margin-top: 20px;"><strong>Total:</strong> <?php echo count($bookings); ?> booking(s) found</p>
        <?php endif; ?>
    </div>
    <?php
}

// Add some admin CSS for better styling
function srb_admin_styles()
{
    $screen = get_current_screen();
    if (strpos($screen->id, 'room-bookings') !== false) {
        ?>
        <style>
            .srb-admin-section {
                margin-top: 20px;
            }

            .srb-admin-section h2 {
                margin-bottom: 15px;
            }

            .srb-admin-section .form-table th {
                width: 200px;
            }

            .button-link-delete {
                color: #a00 !important;
            }

            .button-link-delete:hover {
                color: #dc3232 !important;
            }
        </style>
        <?php
    }
}
add_action('admin_head', 'srb_admin_styles');

function srb_room_booking_form($atts)
{
    // Start session if not already started
    if (!isset($_SESSION)) {
        session_start();
    }

    // Check if password was submitted
    if (isset($_POST['srb_password'])) {
        $submitted_password = $_POST['srb_password'];
        $stored_hash = get_option('srb_access_password_hash');

        if ($stored_hash && wp_check_password($submitted_password, $stored_hash)) {
            $_SESSION['srb_authenticated'] = true;
        } else {
            $error_message = '<div class="srb-error">Incorrect password. Please try again.</div>';
        }
    }

    // Show password form if not authenticated
    if (!isset($_SESSION['srb_authenticated']) || $_SESSION['srb_authenticated'] !== true) {
        ob_start();
        ?>
        <div class="srb-password-protection">
            <h3>Room Booking Access</h3>
            <p>Please enter the access password to view the booking calendar for Okanagan Central Community Church & Ministries.</p>
            <?php echo isset($error_message) ? $error_message : ''; ?>
            <form method="post">
                <div class="srb-form-group">
                    <label for="srb_password">Access Password:</label>
                    <input type="password" id="srb_password" name="srb_password" required>
                    <button type="submit" class="srb-access-btn">Access Calendar</button>
                </div>
            </form>
        </div>
        <?php
        return ob_get_clean();
    }

    // If authenticated, show the calendar
    ob_start();

    // Get all rooms
    $rooms = get_posts([
        'post_type' => 'srb_room',
        'numberposts' => -1,
        'post_status' => 'publish'
    ]);

    ?>
    <div id="srb-calendar-container">
        <!-- Add logout option -->
        <div class="srb-auth-status">
            <span>Calendar Access: Authenticated</span>
            <a href="?srb_logout=1" class="srb-logout-btn">Logout</a>
        </div>

        <div id="srb-calendar-header">
            <button id="srb-prev-month">&lt;</button>
            <h2 id="srb-current-month"></h2>
            <button id="srb-next-month">&gt;</button>
        </div>
        <div id="srb-calendar"></div>

        <!-- Booking Modal -->
        <div id="srb-booking-modal" class="srb-modal">
            <div class="srb-modal-content">
                <div class="srb-modal-header">
                    <h3>Book Room for <span id="srb-selected-date"></span></h3>
                    <span class="srb-close">&times;</span>
                </div>
                <div class="srb-modal-body">
                    <form id="srb-booking-form">
                        <div class="srb-form-group">
                            <label for="srb-time-slot">Time Slot:</label>
                            <div class="srb-time-range">
                                <select id="srb-start-time" name="start_time" required>
                                    <option value="">Start Time</option>
                                    <option value="08:00">8:00 AM</option>
                                    <option value="08:30">8:30 AM</option>
                                    <option value="09:00">9:00 AM</option>
                                    <option value="09:30">9:30 AM</option>
                                    <option value="10:00">10:00 AM</option>
                                    <option value="10:30">10:30 AM</option>
                                    <option value="11:00">11:00 AM</option>
                                    <option value="11:30">11:30 AM</option>
                                    <option value="12:00">12:00 PM</option>
                                    <option value="12:30">12:30 PM</option>
                                    <option value="13:00">1:00 PM</option>
                                    <option value="13:30">1:30 PM</option>
                                    <option value="14:00">2:00 PM</option>
                                    <option value="14:30">2:30 PM</option>
                                    <option value="15:00">3:00 PM</option>
                                    <option value="15:30">3:30 PM</option>
                                    <option value="16:00">4:00 PM</option>
                                    <option value="16:30">4:30 PM</option>
                                    <option value="17:00">5:00 PM</option>
                                    <option value="17:30">5:30 PM</option>
                                    <option value="18:00">6:00 PM</option>
                                </select>
                                <span class="srb-time-separator">to</span>
                                <select id="srb-end-time" name="end_time" required>
                                    <option value="">End Time</option>
                                    <option value="08:30">8:30 AM</option>
                                    <option value="09:00">9:00 AM</option>
                                    <option value="09:30">9:30 AM</option>
                                    <option value="10:00">10:00 AM</option>
                                    <option value="10:30">10:30 AM</option>
                                    <option value="11:00">11:00 AM</option>
                                    <option value="11:30">11:30 AM</option>
                                    <option value="12:00">12:00 PM</option>
                                    <option value="12:30">12:30 PM</option>
                                    <option value="13:00">1:00 PM</option>
                                    <option value="13:30">1:30 PM</option>
                                    <option value="14:00">2:00 PM</option>
                                    <option value="14:30">2:30 PM</option>
                                    <option value="15:00">3:00 PM</option>
                                    <option value="15:30">3:30 PM</option>
                                    <option value="16:00">4:00 PM</option>
                                    <option value="16:30">4:30 PM</option>
                                    <option value="17:00">5:00 PM</option>
                                    <option value="17:30">5:30 PM</option>
                                    <option value="18:00">6:00 PM</option>
                                    <option value="18:30">6:30 PM</option>
                                </select>
                            </div>
                            <div id="srb-time-error" class="srb-error" style="display: none;"></div>
                        </div>

                        <div class="srb-form-group">
                            <label for="srb-room-select">Room:</label>
                            <select id="srb-room-select" name="room_id" required>
                                <option value="">Select a room</option>
                                <?php foreach ($rooms as $room): ?>
                                    <option value="<?php echo $room->ID; ?>">
                                        <?php echo esc_html($room->post_title); ?>
                                        <?php
                                        $capacity = get_post_meta($room->ID, '_srb_capacity', true);
                                        if ($capacity)
                                            echo " (Capacity: $capacity)";
                                        ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="srb-form-group">
                            <label>Is there any setup required for your room booking?</label>
                            <div class="srb-radio-group">
                                <label class="srb-radio-option">
                                    <input type="radio" name="setup_required" value="yes" id="srb-setup-yes">
                                    <span>Yes</span>
                                </label>
                                <label class="srb-radio-option">
                                    <input type="radio" name="setup_required" value="no" id="srb-setup-no" checked>
                                    <span>No</span>
                                </label>
                            </div>
                        </div>

                        <div class="srb-form-group" id="srb-setup-details-group" style="display: none;">
                            <label for="srb-setup-details">Setup Details:</label>
                            <textarea id="srb-setup-details" name="setup_details" rows="2"
                                placeholder="Please describe the setup required (e.g., tables arranged in U-shape, projector needed, etc.)"></textarea>
                        </div>

                        <div class="srb-form-group">
                            <label for="srb-description">Description:</label>
                            <textarea id="srb-description" name="description" rows="3"
                                placeholder="Enter booking description..."></textarea>
                        </div>
                        <div class="srb-form-actions">
                            <button type="submit">Book Room</button>
                            <button type="button" class="srb-cancel">Cancel</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <!-- View Bookings Modal -->
        <div id="srb-view-modal" class="srb-modal">
            <div class="srb-modal-content">
                <div class="srb-modal-header">
                    <h3>Bookings for <span id="srb-view-date"></span></h3>
                    <span class="srb-close">&times;</span>
                </div>
                <div class="srb-modal-body">
                    <div id="srb-bookings-list"></div>
                </div>
            </div>
        </div>
    </div>
    <?php

    return ob_get_clean();
}

// Handle logout
function srb_handle_logout()
{
    if (isset($_GET['srb_logout'])) {
        if (!isset($_SESSION)) {
            session_start();
        }
        unset($_SESSION['srb_authenticated']);
        // Redirect to remove the logout parameter from URL
        wp_redirect(remove_query_arg('srb_logout'));
        exit;
    }
}
add_action('init', 'srb_handle_logout');

add_shortcode('room_booking', 'srb_room_booking_form');