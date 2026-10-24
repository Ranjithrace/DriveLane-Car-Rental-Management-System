-- DriveLane empty database schema (no users, passwords, bookings, or demo data)
CREATE DATABASE IF NOT EXISTS car_rental_db CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;
USE car_rental_db;

CREATE TABLE IF NOT EXISTS users (
    id INT NOT NULL AUTO_INCREMENT,
    username VARCHAR(50) NOT NULL,
    email VARCHAR(100) NOT NULL,
    password VARCHAR(255) NOT NULL,
    role ENUM('customer','staff','admin') NOT NULL DEFAULT 'customer',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_users_username (username),
    UNIQUE KEY uk_users_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS cars (
    id INT NOT NULL AUTO_INCREMENT,
    brand VARCHAR(50) NOT NULL,
    model VARCHAR(50) NOT NULL,
    production_year SMALLINT NOT NULL,
    registration_number VARCHAR(30) NOT NULL,
    price_per_day DECIMAL(10,2) NOT NULL,
    status ENUM('available','maintenance','inactive') NOT NULL DEFAULT 'available',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_cars_registration (registration_number)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS bookings (
    id INT NOT NULL AUTO_INCREMENT,
    customer_id INT NOT NULL,
    car_id INT NOT NULL,
    start_date DATE NOT NULL,
    end_date DATE NOT NULL,
    total_price DECIMAL(10,2) NOT NULL,
    status ENUM('pending','approved','rejected','cancelled','collected','returned') NOT NULL DEFAULT 'pending',
    reviewed_by INT DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_bookings_customer (customer_id),
    KEY idx_bookings_car (car_id),
    KEY idx_bookings_reviewer (reviewed_by),
    CONSTRAINT fk_bookings_customer FOREIGN KEY (customer_id) REFERENCES users (id),
    CONSTRAINT fk_bookings_car FOREIGN KEY (car_id) REFERENCES cars (id),
    CONSTRAINT fk_bookings_reviewer FOREIGN KEY (reviewed_by) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS booking_history (
    id INT NOT NULL AUTO_INCREMENT,
    booking_id INT NOT NULL,
    changed_by INT NOT NULL,
    old_status VARCHAR(20) DEFAULT NULL,
    new_status VARCHAR(20) NOT NULL,
    changed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_history_booking (booking_id),
    KEY idx_history_actor (changed_by),
    CONSTRAINT fk_history_booking FOREIGN KEY (booking_id) REFERENCES bookings (id),
    CONSTRAINT fk_history_actor FOREIGN KEY (changed_by) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS car_images (
    id INT NOT NULL AUTO_INCREMENT,
    car_id INT NOT NULL,
    image_path VARCHAR(255) NOT NULL,
    is_primary TINYINT(1) NOT NULL DEFAULT 0,
    sort_order INT NOT NULL DEFAULT 0,
    uploaded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_images_car (car_id),
    CONSTRAINT fk_images_car FOREIGN KEY (car_id) REFERENCES cars (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
