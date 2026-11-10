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
Employee file tabs: personal, contact, emergency contact; social security and tax numbers stay masked until shown.
New Employment tab: work shift and contract history; a new contract closes the previous one.
New Documents tab: upload contracts and ID copies to protected storage; openings are logged.
Employee files gain Notes (note, caution, warning) and a History tab of every change.
Download the invoice as a PDF from the booking screen.
Booking and payment e-mails now attach the PDF invoice or receipt.
Unpaid bookings can be released automatically after their payment deadline, with an optional grace period.
New Occupancy report: occupancy rate per day and period, peak rooms, and rate per room type.
New Revenue breakdown report: sales by room type, rate plan and staff member.
Every report can be exported to Excel (XLSX), one sheet per table.
Booking and guest exports can be made in Excel (XLSX), however many rows.
Archive and remove: export a past period, verify the file, then delete its finished bookings.
Scheduled exports: weekly or monthly files of the previous week or month, with the next run shown.
Archive and remove now checks every booking line is in the file before deleting it.
Fixed: a failed delete during archive and remove could leave a booking partly removed.
Fixed: scheduled exports could overwrite schedule edits, run twice, or retry a failed run repeatedly.
New My profile screen: staff update their own details; restricted fields show a lock.
Staff profile changes to a restricted field are refused with a message, never silently dropped.
Staff can change their own password on My profile; other devices are signed out.
Staff can set their own PIN, confirming with the current PIN or password.
Add-ons can now add their own tabs to the staff file (for example payroll).
Date and time fields now use the modern calendar and time pickers.
Staff phone fields use the country picker with flags and number validation.
Staff files say "Social security number" instead of "CNPS number".
New Payroll and HR feature: pay runs, payslips, deductions, shifts, time off and My payroll.
Settings → Payroll: set your own social contributions and income tax brackets for any country.
Country add-ons can supply their own tax and contribution calculator instead.
Payroll data from the Residence TATA add-on is moved over automatically on update.
Pro features screen no longer shows internal module codes such as M14 next to features.
Staff access list shows each person's access role as a coloured badge beside their name.
Staff, Payroll, Access roles and Staff access now sit together under Staff & access in the menu.
The access roles that come with Pro are now labelled Built-in instead of Template.
New staff can use an existing sign-in, so managers already on the dashboard get My payroll.
Staff without a PIN are now sent to My profile to choose one, not told to ask a manager.
My paychecks and My profile now appear in the menu under My account.
Complete French translation, included in the plugin.
Revenue breakdown report also works by check-out date and with times.
History of changes now shows on rooms, floors, rate plans, pricing rules, blocked dates and pay runs.
Bonus rules: fixed or percentage bonuses every pay run adds, taxable or not, for chosen staff.
Pay runs list active staff without a pay schedule; recalculating adds newly scheduled staff.
Removed Archive and remove: bookings are never deleted from the Exports screen.
Entered PIN now survives page refresh and new tabs; signing out always asks again.
New PIN validity option "Until sign-out"; the default stays 5 minutes.
PIN validity is now idle time: each protected page or action restarts it.
Password managers no longer offer to autofill the PIN field.
Pay run line breakdown shows each rate on the same line as its label.
Pay run line editor regrouped: period inputs with units, and a tidier one-off items list.
Staff Pay tab: salary, currency and period on one line; new document file picker.
Staff file: Contact tab merged into Personal, as its own section.
Pay run line: footer stays visible; one-off items fold to one line with Edit.
Removing a one-off pay run item now asks for confirmation first.
A one-off item taxed on its own now starts at the salary tax rate.
Pay run line stays open after saving, showing the recalculated figures; header stays fixed.
Booking QR codes on guest e-mails, booking page, invoices and receipts; scan to open the booking.
New Scan button in the dashboard: phone camera, handheld scanner or typed booking reference.
Fixed: Pro features (staff, payroll, activity log) not loading on sites with a custom plugin load order.
Staff access: "PIN set" and "No PIN" badges now have distinct colours.
Settings → Passcodes: "One PIN covers" now explains each option and where pages are protected.
Staff, payroll, pricing, calendar sync and My profile screens now use the full page width.
Scan box now asks for "the booking reference", whatever its format.
Scanning a renumbered booking's old reference still opens the booking.
Payslips show the hotel name beside the logo; the legal name sits underneath.
Pay run: the staff-without-schedule notice now sits on a white card with a warning icon.
Pay tab: overtime, paid or unpaid time off and sick pay can be counted per day.
Pay tab: vacation pay can be a percentage or a fixed amount each pay period.
Pay tab: bonus and commission take an amount or a % of base pay, paid each period.
Pay tab: transport allowance takes an amount paid each pay period.
Pay tab: gratification is set up like vacation pay — percentage or amount, paid or kept.
Vacation pay and gratification can count transport, gratification and bonus rules such as seniority.
Pay tab: a Bonus rules panel shows which bonus rules (such as seniority) the employee gets.
Pay tab: new Additions — an amount or % of base pay added to every payslip.
Payroll → Work shifts: print the work schedule of every employee for a week or month.
Staff: each employee can have a photo, shown on the staff list and staff file.
The account menu shows the signed-in employee's photo when one is set.
Add employee now asks for date of birth, marital status, address and number of children.
Staff: Work shift on the Employment tab and Add employee now uses Payroll work shifts.
Fixed: the Pay tab bonus was paid every period; now only in runs set to pay bonuses.
Scanning a booking QR code opens a quick card: check in, check out or record payment.
New Cash register: staff open and close their register per shift, with per-method totals.
Cash register close counts the cash and keeps the difference; managers see every register.
Optional setting: staff must open their cash register before taking a payment.
The Dashboard now shows your cash register: open, close and per-method totals at a glance.
Fixed: every staff member could use the cash register; now only roles given access, like Receptionist.
Payroll list: pay runs that pay bonuses are flagged with a Bonus badge.
New Settings → Staff: departments and job titles to pick from on staff files and contracts.
Staff list: filter and sort by department, job title and shift.
Settings → Payroll: social contributions redesigned as a collapsible list with totals.
New: add the common social contributions (pension, health, unemployment, work accident) in one click.
Settings → Payroll: income tax redesigned, with a clear brackets table (from, up to, rate).
Bonus rules: choose employees from a searchable list (name or number) instead of checkboxes.
Fixed: income tax brackets refused on save; the last bracket is now always “and above”.
Social contributions now also show for a country add-on's calculator that uses them.
Developers: the tax brackets table is shared with add-ons as window.rtbpPro.ui.BracketsTable.
Deleting payroll contributions, tax brackets, bonuses, pay schedules or work shifts requires typing DELETE.
