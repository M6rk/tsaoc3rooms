# TSA0C3 Room Booking Plugin

A simple and user-friendly WordPress room booking system with calendar view, designed for easy room management and scheduling.

## Features

- **Calendar View**: Large, responsive calendar interface for easy booking visualization
- **Mobile Responsive**: Works perfectly on phones, tablets, and desktop computers
- **Room Management**: Create and manage multiple rooms with capacity and equipment details
- **Time Slot Booking**: Book rooms with flexible start and end times (8 AM - 6:30 PM)
- **Conflict Prevention**: Automatic detection and prevention of double bookings
- **Password Protection**: Simple password-based access control
- **Setup Requirements**: Track whether special setup is needed for bookings
- **Admin Interface**: Clean, tabbed admin interface for managing rooms and bookings

## Installation

### Method 1: Upload via WordPress Admin (Recommended)

1. **Create Plugin ZIP File**
   - Create a new folder called `tsa0c3-room-booking`
   - Copy all plugin files into this folder:
     - `room-booking.php`
     - `assets/` folder (containing `css/` and `js/` subfolders)
   - Compress the folder into a ZIP file

2. **Upload to WordPress**
   - Log in to your WordPress admin panel
   - Go to **Plugins → Add New**
   - Click **Upload Plugin**
   - Choose your ZIP file and click **Install Now**
   - Click **Activate Plugin**

### Method 2: FTP Upload

1. **Upload Files**
   - Connect to your website via FTP
   - Navigate to `/wp-content/plugins/`
   - Create a new folder called `tsa0c3-room-booking`
   - Upload all plugin files to this folder

2. **Activate Plugin**
   - Go to your WordPress admin panel
   - Navigate to **Plugins → Installed Plugins**
   - Find "TSA0C3 Room Booking" and click **Activate**

## Setup

### 1. Initial Configuration

After activation, you'll see a new **Room Bookings** menu in your WordPress admin:

1. **Go to Room Bookings → Settings**
   - Set a password for calendar access
   - Confirm the password
   - Click **Update Password**

### 2. Create Rooms

1. **Go to Room Bookings → Manage Rooms**
   - Click **Add New Room**
   - Enter room name (e.g., "Conference Room A")
   - Add capacity (number of people)
   - List available equipment
   - Click **Publish**

### 3. Display the Calendar

Add the booking calendar to any page or post using the shortcode:

```
[room_booking]
```

**To add to a page:**
1. Go to **Pages → Add New** (or edit existing page)
2. Add the shortcode `[room_booking]` in the content
3. Click **Publish** or **Update**

## Usage

### For End Users

1. **Access the Calendar**
   - Visit the page with the `[room_booking]` shortcode
   - Enter the password you set in the admin settings

2. **Make a Booking**
   - Click on any date in the calendar
   - Select a room from the dropdown
   - Choose start and end times (30-minute intervals)
   - Add a description of your booking
   - Indicate if special setup is required
   - Click **Book Room**

3. **View Existing Bookings**
   - Days with bookings show a red indicator with the number of bookings
   - Click on a day with bookings to see details
   - You can add additional bookings to the same day

### For Administrators

1. **Manage Rooms**
   - Edit room details anytime from **Room Bookings → Manage Rooms**
   - Delete rooms that are no longer needed

2. **View All Bookings**
   - See all bookings from **Room Bookings → All Bookings**
   - Filter by date or room
   - Delete bookings if needed

3. **Change Settings**
   - Update the access password anytime
   - View usage statistics

## Technical Details

### Time Slots
- Available times: 8:00 AM to 6:30 PM
- 30-minute minimum booking duration
- 8-hour maximum booking duration
- Automatic conflict detection prevents double bookings

### Browser Requirements
- Modern browsers (Chrome, Firefox, Safari, Edge)
- JavaScript must be enabled
- Responsive design works on all screen sizes

### WordPress Requirements
- WordPress 5.0 or higher
- PHP 7.4 or higher
- jQuery (included with WordPress)

## Troubleshooting

### Calendar Not Loading
- Ensure JavaScript is enabled in the browser
- Check that the shortcode `[room_booking]` is correctly placed
- Verify the plugin is activated

### Password Issues
- Reset the password in **Room Bookings → Settings**
- Clear browser cache and try again

### Booking Conflicts
- The system automatically prevents double bookings
- If you see a conflict error, choose a different time slot
- Check existing bookings for that day

### Mobile Display Issues
- The calendar is designed to be mobile-responsive
- If issues persist, try clearing browser cache

## Support

For technical issues or questions about the plugin, please check:

1. WordPress admin error logs
2. Browser console for JavaScript errors
3. Plugin settings and configuration

## File Structure

```
tsa0c3-room-booking/
├── room-booking.php          # Main plugin file
├── assets/
│   ├── css/
│   │   └── calendar.css      # Calendar styling
│   └── js/
│       └── calendar.js       # Calendar functionality
└── README.md                 # This file
```

## License

This plugin is created for TSA0C3 internal use.