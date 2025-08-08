jQuery(document).ready(function ($) {
    let currentDate = new Date();
    let selectedDate = null;
    let existingBookings = []; // Keep track of existing bookings for the selected date

    // Start up the calendar
    function initCalendar() {
        renderCalendar();
        bindEvents();
    }

    // Draw the calendar for the current month
    function renderCalendar() {
        const year = currentDate.getFullYear();
        const month = currentDate.getMonth();

        // Update the month/year header
        const monthNames = [
            'January', 'February', 'March', 'April', 'May', 'June',
            'July', 'August', 'September', 'October', 'November', 'December'
        ];
        $('#srb-current-month').text(monthNames[month] + ' ' + year);

        // Clear calendar and start fresh
        $('#srb-calendar').empty();

        // Add the day headers (Sun, Mon, etc.)
        const dayHeaders = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
        dayHeaders.forEach(day => {
            $('#srb-calendar').append('<div class="srb-calendar-header">' + day + '</div>');
        });

        // Figure out where to start the calendar
        const firstDay = new Date(year, month, 1).getDay();
        const daysInMonth = new Date(year, month + 1, 0).getDate();
        const daysInPrevMonth = new Date(year, month, 0).getDate();

        // Fill in the previous month's trailing days (grayed out)
        for (let i = firstDay - 1; i >= 0; i--) {
            const day = daysInPrevMonth - i;
            const dateStr = formatDate(new Date(year, month - 1, day));
            $('#srb-calendar').append(
                '<div class="srb-calendar-day srb-other-month" data-date="' + dateStr + '">' +
                '<div class="srb-day-number">' + day + '</div>' +
                '</div>'
            );
        }

        // Add all the days for this month
        for (let day = 1; day <= daysInMonth; day++) {
            const date = new Date(year, month, day);
            const dateStr = formatDate(date);
            const today = new Date();
            const isToday = date.toDateString() === today.toDateString();

            const dayElement = $(
                '<div class="srb-calendar-day ' + (isToday ? 'srb-today' : '') + '" data-date="' + dateStr + '">' +
                '<div class="srb-day-number">' + day + '</div>' +
                '</div>'
            );

            $('#srb-calendar').append(dayElement);

            // Check if this day has any bookings
            loadBookingsForDay(dateStr, dayElement);
        }

        // Fill in the next month's leading days (grayed out)
        const totalCells = $('#srb-calendar .srb-calendar-day').length + 7; // +7 for day headers
        const remainingCells = 42 - (totalCells - 7); // 42 = 6 weeks * 7 days

        for (let day = 1; day <= remainingCells; day++) {
            const dateStr = formatDate(new Date(year, month + 1, day));
            $('#srb-calendar').append(
                '<div class="srb-calendar-day srb-other-month" data-date="' + dateStr + '">' +
                '<div class="srb-day-number">' + day + '</div>' +
                '</div>'
            );
        }
    }

    // Get bookings for a specific day and show all of them in a scrollable preview
    function loadBookingsForDay(dateStr, dayElement) {
        $.post(srb_ajax.ajax_url, {
            action: 'srb_get_bookings',
            date: dateStr,
            nonce: srb_ajax.nonce
        }, function (response) {
            if (response.success && response.data.length > 0) {
                dayElement.addClass('srb-has-bookings');
                dayElement.append('<div class="srb-booking-indicator">' + response.data.length + '</div>');

                // Create a scrollable preview container to show all bookings (not just the first one)
                const previewContainer = $('<div class="srb-booking-preview-container"></div>');
                
                // Add each booking as a separate preview item
                response.data.forEach(function(booking) {
                    const preview = booking.room_name + ' - ' + booking.time_slot;
                    previewContainer.append('<div class="srb-booking-preview-item">' + preview + '</div>');
                });
                
                dayElement.append(previewContainer);
            }
        });
    }

    // Get existing bookings to check for conflicts
    function loadExistingBookings(date, roomId) {
        return $.post(srb_ajax.ajax_url, {
            action: 'srb_get_bookings',
            date: date,
            room_id: roomId, // Optional: filter by room
            nonce: srb_ajax.nonce
        });
    }

    // Set up all the click handlers and events
    function bindEvents() {
        // Month navigation buttons
        $('#srb-prev-month').click(function () {
            currentDate.setMonth(currentDate.getMonth() - 1);
            renderCalendar();
        });

        $('#srb-next-month').click(function () {
            currentDate.setMonth(currentDate.getMonth() + 1);
            renderCalendar();
        });

        // When someone clicks on a day
        $(document).on('click', '.srb-calendar-day:not(.srb-other-month)', function () {
            selectedDate = $(this).data('date');

            if ($(this).hasClass('srb-has-bookings')) {
                showBookingsModal(selectedDate); // Show existing bookings
            } else {
                showBookingModal(selectedDate); // Create new booking
            }
        });

        // Close modal buttons
        $('.srb-close, .srb-cancel').click(function () {
            $('.srb-modal').hide();
        });

        // Close modal when clicking outside of it
        $('.srb-modal').click(function (e) {
            if (e.target === this) {
                $(this).hide();
            }
        });

        // When time selection changes, update available end times and check conflicts
        $('#srb-start-time').change(function () {
            updateEndTimeOptions();
            validateTimeRange();
        });
        
        $('#srb-end-time, #srb-room-select').change(function () {
            validateTimeRange();
        });

        // Handle setup required radio button changes
        $('input[name="setup_required"]').change(function () {
            const setupDetailsGroup = $('#srb-setup-details-group');
            if ($(this).val() === 'yes') {
                setupDetailsGroup.slideDown(300);
                $('#srb-setup-details').attr('required', true);
            } else {
                setupDetailsGroup.slideUp(300);
                $('#srb-setup-details').attr('required', false).val('');
            }
        });

        // When the booking form is submitted
        $('#srb-booking-form').submit(function (e) {
            e.preventDefault();
            submitBooking();
        });

        // "Add Booking" button clicks (these are created dynamically)
        $(document).on('click', '.srb-add-booking-btn', function () {
            $('#srb-view-modal').hide();
            showBookingModal(selectedDate);
        });
    }

    // Check if the selected times are valid and don't conflict
    function validateTimeRange() {
        const startTime = $('#srb-start-time').val();
        const endTime = $('#srb-end-time').val();
        const roomId = $('#srb-room-select').val();
        const errorDiv = $('#srb-time-error');

        if (!startTime || !endTime) {
            errorDiv.hide();
            return true;
        }

        // Convert times to minutes to make comparison easier
        const startMinutes = timeToMinutes(startTime);
        const endMinutes = timeToMinutes(endTime);

        // Make sure the times make sense
        if (startMinutes >= endMinutes) {
            errorDiv.text('End time must be after start time').show();
            return false;
        }

        if ((endMinutes - startMinutes) < 30) {
            errorDiv.text('Minimum booking duration is 30 minutes').show();
            return false;
        }

        if ((endMinutes - startMinutes) > 480) { // 8 hours = 480 minutes
            errorDiv.text('Maximum booking duration is 8 hours').show();
            return false;
        }

        // If a room is selected, check for time conflicts
        if (roomId && selectedDate) {
            checkTimeConflicts(selectedDate, roomId, startTime, endTime);
        } else {
            errorDiv.hide();
        }

        return true;
    }

    // Ask the server if this time slot conflicts with existing bookings
    function checkTimeConflicts(date, roomId, startTime, endTime) {
        const errorDiv = $('#srb-time-error');
        
        $.post(srb_ajax.ajax_url, {
            action: 'srb_check_conflicts',
            date: date,
            room_id: roomId,
            start_time: startTime,
            end_time: endTime,
            nonce: srb_ajax.nonce
        }, function(response) {
            if (response.success) {
                if (response.data.hasConflict) {
                    errorDiv.text('This time slot conflicts with an existing booking: ' + response.data.conflictDetails).show();
                } else {
                    errorDiv.hide();
                }
            }
        });
    }

    // Helper function to convert time like "14:30" to minutes (870)
    function timeToMinutes(timeStr) {
        if (!timeStr) return 0;
        const timeParts = timeStr.split(':').map(Number);
        return timeParts[0] * 60 + timeParts[1];
    }

    // Update the end time dropdown based on what start time is selected
    function updateEndTimeOptions() {
        const startTime = $('#srb-start-time').val();
        const endTimeSelect = $('#srb-end-time');
        const currentEndTime = endTimeSelect.val();
        
        // Remove all end time options except the "Select end time" placeholder
        endTimeSelect.find('option:not([value=""])').remove();
        
        if (!startTime) {
            // If no start time is selected, show all possible end times
            const allEndTimes = [
                {value: "08:30", text: "8:30 AM"},
                {value: "09:00", text: "9:00 AM"},
                {value: "09:30", text: "9:30 AM"},
                {value: "10:00", text: "10:00 AM"},
                {value: "10:30", text: "10:30 AM"},
                {value: "11:00", text: "11:00 AM"},
                {value: "11:30", text: "11:30 AM"},
                {value: "12:00", text: "12:00 PM"},
                {value: "12:30", text: "12:30 PM"},
                {value: "13:00", text: "1:00 PM"},
                {value: "13:30", text: "1:30 PM"},
                {value: "14:00", text: "2:00 PM"},
                {value: "14:30", text: "2:30 PM"},
                {value: "15:00", text: "3:00 PM"},
                {value: "15:30", text: "3:30 PM"},
                {value: "16:00", text: "4:00 PM"},
                {value: "16:30", text: "4:30 PM"},
                {value: "17:00", text: "5:00 PM"},
                {value: "17:30", text: "5:30 PM"},
                {value: "18:00", text: "6:00 PM"},
                {value: "18:30", text: "6:30 PM"}
            ];
            
            allEndTimes.forEach(time => {
                endTimeSelect.append('<option value="' + time.value + '">' + time.text + '</option>');
            });
            return;
        }
        
        // Convert start time to minutes so we can do math
        const startMinutes = timeToMinutes(startTime);
        const maxMinutes = startMinutes + (8 * 60); // Maximum 8 hours after start time
        
        // All possible time slots with their minute values
        const timeSlots = [
            {value: "08:30", text: "8:30 AM", minutes: 510},
            {value: "09:00", text: "9:00 AM", minutes: 540},
            {value: "09:30", text: "9:30 AM", minutes: 570},
            {value: "10:00", text: "10:00 AM", minutes: 600},
            {value: "10:30", text: "10:30 AM", minutes: 630},
            {value: "11:00", text: "11:00 AM", minutes: 660},
            {value: "11:30", text: "11:30 AM", minutes: 690},
            {value: "12:00", text: "12:00 PM", minutes: 720},
            {value: "12:30", text: "12:30 PM", minutes: 750},
            {value: "13:00", text: "1:00 PM", minutes: 780},
            {value: "13:30", text: "1:30 PM", minutes: 810},
            {value: "14:00", text: "2:00 PM", minutes: 840},
            {value: "14:30", text: "2:30 PM", minutes: 870},
            {value: "15:00", text: "3:00 PM", minutes: 900},
            {value: "15:30", text: "3:30 PM", minutes: 930},
            {value: "16:00", text: "4:00 PM", minutes: 960},
            {value: "16:30", text: "4:30 PM", minutes: 990},
            {value: "17:00", text: "5:00 PM", minutes: 1020},
            {value: "17:30", text: "5:30 PM", minutes: 1050},
            {value: "18:00", text: "6:00 PM", minutes: 1080},
            {value: "18:30", text: "6:30 PM", minutes: 1110}
        ];
        
        // Only show end times that are after the start time and within 8 hours
        timeSlots.forEach(slot => {
            if (slot.minutes > startMinutes && slot.minutes <= maxMinutes) {
                endTimeSelect.append('<option value="' + slot.value + '">' + slot.text + '</option>');
            }
        });
        
        // If the previously selected end time is still valid, keep it selected
        if (currentEndTime && endTimeSelect.find('option[value="' + currentEndTime + '"]').length > 0) {
            endTimeSelect.val(currentEndTime);
        } else {
            endTimeSelect.val('');
        }
    }

    // Open the booking creation modal
    function showBookingModal(date) {
        selectedDate = date;
        $('#srb-selected-date').text(formatDateForDisplay(date));
        $('#srb-booking-modal').show();
        $('#srb-booking-form')[0].reset();

        // Hide setup details field when modal opens
        $('#srb-setup-details-field').hide();

        // Clear any previous error or success messages
        $('.srb-error, .srb-success').remove();
        $('#srb-time-error').hide();
    }

    // Open the modal to view existing bookings
    function showBookingsModal(date) {
        $('#srb-view-date').text(formatDateForDisplay(date));
        $('#srb-view-modal').show();

        // Show loading message while we fetch bookings
        $('#srb-bookings-list').html('<div class="srb-loading">Loading bookings...</div>');

        $.post(srb_ajax.ajax_url, {
            action: 'srb_get_bookings',
            date: date,
            nonce: srb_ajax.nonce
        }, function (response) {
            if (response.success) {
                displayBookings(response.data);
            } else {
                $('#srb-bookings-list').html('<div class="srb-error">Failed to load bookings.</div>');
            }
        });
    }

    // Show the list of bookings in the modal
    function displayBookings(bookings) {
        const bookingsList = $('#srb-bookings-list');
        bookingsList.empty();

        if (bookings.length === 0) {
            bookingsList.append('<div class="srb-no-bookings">No bookings for this date.</div>');
            bookingsList.append('<button class="srb-add-booking-btn" style="margin-top: 15px; padding: 10px 20px; background: #EE2D3D; color: white; border: none; border-radius: 4px; cursor: pointer;">Add Booking</button>');
            return;
        }

        bookings.forEach(function (booking) {
            const description = booking.description || 'No description provided';
            const bookingHtml =
                '<div class="srb-booking-item">' +
                '<div class="srb-booking-time">' + booking.time_slot + '</div>' +
                '<div class="srb-booking-room">' + booking.room_name + '</div>' +
                '<div class="srb-booking-description">' + description + '</div>' +
                '</div>';
            bookingsList.append(bookingHtml);
        });

        // Add a button to create another booking on this day
        bookingsList.append('<button class="srb-add-booking-btn" style="margin-top: 15px; padding: 10px 20px; background: #EE2D3D; color: white; border: none; border-radius: 4px; cursor: pointer;">Add Another Booking</button>');
    }

    // Handle the booking form submission
    function submitBooking() {
        const startTime = $('#srb-start-time').val();
        const endTime = $('#srb-end-time').val();
        const roomId = $('#srb-room-select').val();
        const timeSlot = startTime + '-' + endTime;

        const formData = {
            action: 'srb_create_booking',
            date: selectedDate,
            room_id: roomId,
            description: $('#srb-description').val(),
            time_slot: timeSlot,
            start_time: startTime,
            end_time: endTime,
            setup_required: $('input[name="setup_required"]:checked').val(),
            setup_details: $('#srb-setup-details').val(),
            nonce: srb_ajax.nonce
        };

        // Make sure all required fields are filled out
        if (!roomId || !startTime || !endTime) {
            showMessage('Please select start time, end time, and a room.', 'error');
            return;
        }

        // Double-check for conflicts before submitting
        $.post(srb_ajax.ajax_url, {
            action: 'srb_check_conflicts',
            date: selectedDate,
            room_id: roomId,
            start_time: startTime,
            end_time: endTime,
            nonce: srb_ajax.nonce
        }, function(response) {
            if (response.success && response.data.hasConflict) {
                showMessage('Cannot book: ' + response.data.conflictDetails, 'error');
                return;
            }

            // If no conflicts, go ahead and create the booking
            proceedWithBooking(formData);
        });
    }

    // Actually create the booking (called after conflict check passes)
    function proceedWithBooking(formData) {
        // Disable the submit button so they can't double-click
        const submitBtn = $('#srb-booking-form button[type="submit"]');
        const originalText = submitBtn.text();
        submitBtn.prop('disabled', true).text('Creating booking...');

        $.post(srb_ajax.ajax_url, formData, function (response) {
            if (response.success) {
                showMessage('Booking created successfully!', 'success');
                $('#srb-booking-modal').hide();
                renderCalendar(); // Refresh calendar to show the new booking
            } else {
                showMessage(response.data || 'Failed to create booking.', 'error');
            }
        }).fail(function () {
            showMessage('Network error. Please try again.', 'error');
        }).always(function () {
            // Re-enable the submit button
            submitBtn.prop('disabled', false).text(originalText);
        });
    }

    // Show success or error messages to the user
    function showMessage(message, type) {
        const messageClass = type === 'error' ? 'srb-error' : 'srb-success';
        const messageHtml = '<div class="' + messageClass + '">' + message + '</div>';

        // Remove any existing messages first
        $('.srb-error, .srb-success').remove();

        // Add the new message to the top of the form
        $('#srb-booking-form').prepend(messageHtml);

        // Auto-hide success messages after 3 seconds
        if (type === 'success') {
            setTimeout(function () {
                $('.srb-success').fadeOut();
            }, 3000);
        }
    }

    // Convert a date object to YYYY-MM-DD format for the database
    function formatDate(date) {
        const year = date.getFullYear();
        const month = String(date.getMonth() + 1).padStart(2, '0');
        const day = String(date.getDate()).padStart(2, '0');
        return year + '-' + month + '-' + day;
    }

    // Convert a date string to a readable format like "January 15, 2024"
    function formatDateForDisplay(dateStr) {
        const date = new Date(dateStr + 'T00:00:00');
        const options = {
            year: 'numeric',
            month: 'long',
            day: 'numeric'
        };
        return date.toLocaleDateString('en-US', options);
    }

    // Start everything up when the page loads
    initCalendar();

    // Let people close modals with the Escape key
    $(document).keydown(function (e) {
        if (e.key === 'Escape') {
            $('.srb-modal').hide();
        }
    });

    // Refresh the calendar every 5 minutes to show new bookings from other users
    setInterval(function () {
        renderCalendar();
    }, 300000); // 300000 ms = 5 minutes
});
