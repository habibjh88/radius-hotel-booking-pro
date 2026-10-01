=== Radius Hotel Booking Pro ===
Contributors: radiustheme
Tags: hotel booking, activity log, pricing rules, ical, pdf invoice
Requires at least: 6.5
Tested up to: 6.8
Requires PHP: 8.0
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Pro features for Radius Hotel Booking. Requires the free Radius Hotel Booking plugin.

== Description ==

Adds Pro features to Radius Hotel Booking. Switch each one on or off under
Radius Hotel Booking → Settings → Pro features:

* Activity log — who did what, with before-and-after values and archiving
* Advanced staff access — passcode-protected actions, PINs, custom roles, per-person overrides
* Advanced pricing rules — seasonal, occupancy, early-bird and last-minute
* iCal calendar sync
* PDF invoices and receipts
* Automatic release of unpaid bookings
* Advanced reports and Excel export
* Advanced exports and archiving
* Staff records and staff self-service

A feature that is switched off adds nothing to the site; its data is kept.

== Installation ==

1. Install and activate Radius Hotel Booking (free).
2. Copy this plugin into `wp-content/plugins/`, run `composer install` and `npm install && npm run build`.
3. Activate Radius Hotel Booking Pro.

== Changelog ==

= 1.0.0 ( UNRELEASE ) =
Added a Pro features screen to switch each Pro feature on or off.
Passcode-protected actions: staff confirm with a personal PIN or a shared fallback PIN.
Five wrong PINs lock a staff member out of unlocking for 15 minutes.
Protected actions now ask for the PIN in a dialog, then continue without a second click.
New Settings → Passcodes tab: PIN validity, scope, fallback PIN and the locked message.
Named access roles with six editable templates, and per-person permission overrides.
New Access roles and Staff access screens; Settings → Permissions gains the Passcode level.
Choose which profile fields staff may change: default, per access role and per person.
New activity log: every staff action is stored with who, when, device and before-and-after values.
New command wp radius-hotel-booking log:verify finds any activity log entry that was altered.
New Activity log screen: filter by staff, date, kind and action, search, and see before-and-after values.
Automatic and on-demand log archives (CSV and JSON), with optional removal after the file is checked.
Fixed: the Pro features and Passcodes settings tabs were hidden.
The activity log is sealed with a secret key: back up its key file with the site.
Moving a room to another room type now asks for a PIN by default.
Seasonal pricing: reusable adjustment templates applied to date ranges, with priorities and room scopes.
New Pricing rules screen: seasonal rules on a year timeline, and their templates.
Occupancy pricing, early-bird and last-minute discounts, applied in one documented order.
Pricing rules screen gains Occupancy and Booking window tabs to manage those rules.
Each room can publish its availability as a private iCal link for Booking.com, Airbnb and others.
New Calendar sync screen: read Booking.com and Airbnb calendars into blocked dates automatically.
New Staff screen: employees with job, shift, access role; deactivate blocks sign-in immediately.
Add employees with a guided form; set passwords, PINs and access roles from their file.
Employee file tabs: personal, contact, emergency contact; CNPS and tax numbers stay masked until shown.
New Employment tab: work shift and contract history; a new contract closes the previous one.
New Documents tab: upload contracts and ID copies to protected storage; openings are logged.
Employee files gain Notes (note, caution, warning) and a History tab of every change.
Download the invoice as a PDF from the booking screen.
Booking and payment e-mails now attach the PDF invoice or receipt.
Unpaid bookings can be released automatically after their payment deadline, with an optional grace period.
