# DriveLane — Car Rental Management System

DriveLane is a role-based car rental management web application built with **PHP, MariaDB/MySQL, HTML, CSS and JavaScript**. Customers can browse vehicles and request rentals, rental staff manage the fleet and process bookings, and administrators monitor the system and manage user roles.

Developed as **Computer Networks and Internet Technologies — Project 2** at Kaunas University of Technology (KTU).

## Features

### Customer
- Create an account, sign in and manage profile details/password.
- Browse the fleet, view car photographs and details, and search/filter/sort vehicles.
- Request rentals for selected dates and view estimated rental cost.
- Track rental requests and cancel their own pending bookings.

### Rental staff
- Add and edit vehicles, prices and availability statuses.
- Upload multiple photographs, select the primary photo and delete photos.
- Review, search and filter booking requests; approve or reject requests.
- Record vehicle collection and return, subject to rental-date rules.
- View booking activity history and confirmed rentals on a schedule.

### Administrator
- View system-level statistics, fleet details and bookings.
- Promote customers to rental staff and change staff accounts back to customers.
- Inspect booking activity logs and reporting dashboards.
- Filter reports and export users, cars, bookings and booking activity as CSV.

## Booking workflow

`Pending → Approved → Collected → Returned`  
`Pending → Rejected` or `Pending → Cancelled` (customer cancellation)

Confirmed bookings are checked for overlapping rental dates before approval. Rental-date checks restrict collection and return actions. Important booking status changes are recorded in the `booking_history` table.

## Technology

- PHP 8.2 with MySQLi and prepared statements
- MariaDB/MySQL, InnoDB and foreign keys
- HTML, CSS and JavaScript
- Apache and XAMPP for local development

## Run locally (Windows + XAMPP)

1. Install XAMPP with PHP and MySQL/MariaDB, and start **Apache** and **MySQL**.
2. Copy the project to `C:\xampp\htdocs\car_rental` (or the equivalent path for your XAMPP installation).
3. In phpMyAdmin, import [`database_schema.sql`](database_schema.sql). The script creates the `car_rental_db` database and its empty tables. **It does not contain accounts or personal data.**
4. Copy `database.example.php` to `database.php` and set the database host, username, password and database name for your local installation. Do not commit `database.php`.
5. Open `http://localhost/car_rental/` and register a customer account.
6. For an initial local administrator account, register an account and use phpMyAdmin to change **only that account's** `users.role` to `admin`. Log in again to access the Admin Dashboard. The administrator can promote other customers to rental staff.

Car photographs can be added from the staff interface. The GitHub repository intentionally excludes local uploaded photographs; the application can show placeholders until you upload your own images.

## Role permissions and security

- User roles are checked against the database on protected pages.
- Passwords are stored as hashes, not plain text.
- Mutating forms use CSRF tokens and input validation.
- Booking processing uses database transactions and checks conflicting rental dates.
- Image uploads limit file types and sizes; uploads are stored separately from PHP source code.

## Notes

This is an academic prototype intended to run locally. It **does not process payments**, and the reported value of returned rentals is not verified revenue. No real user database or credentials are included in this repository.
