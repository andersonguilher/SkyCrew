-- Database Draft V3 - Complete Structure with Auth & Financials

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";

-- Disable foreign key checks for drop
SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS `roster_assignments`;
DROP TABLE IF EXISTS `pilot_preferences`;
DROP TABLE IF EXISTS `pilot_aircraft_prefs`;
DROP TABLE IF EXISTS `pilots`;
DROP TABLE IF EXISTS `users`;
DROP TABLE IF EXISTS `flights_master`;
DROP TABLE IF EXISTS `fleet`;
DROP TABLE IF EXISTS `system_settings`;
DROP TABLE IF EXISTS `ranks`;
DROP TABLE IF EXISTS `flight_reports`;
DROP TABLE IF EXISTS `expense_venues`;
DROP TABLE IF EXISTS `airports`;

SET FOREIGN_KEY_CHECKS = 1;

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `email` varchar(150) NOT NULL,
  `password` varchar(255) NOT NULL,
  `role` enum('admin','pilot') NOT NULL DEFAULT 'pilot',
  `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO `users` (`id`, `email`, `password`, `role`) VALUES
(1, 'admin@skycrew.com', '$2y$10$DJHPYPiwBO7sqUPLW0MCzeyZ0MsnvHl1HpVvQCmBxkuFtRP.gTb.i', 'admin'),
(2, 'shepard@skycrew.com', '$2y$10$DJHPYPiwBO7sqUPLW0MCzeyZ0MsnvHl1HpVvQCmBxkuFtRP.gTb.i', 'pilot'),
(3, 'maverick@skycrew.com', '$2y$10$DJHPYPiwBO7sqUPLW0MCzeyZ0MsnvHl1HpVvQCmBxkuFtRP.gTb.i', 'pilot');

-- --------------------------------------------------------

--
-- Table structure for table `ranks`
--

CREATE TABLE `ranks` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `rank_name` varchar(50) NOT NULL,
  `min_hours` decimal(10,2) NOT NULL,
  `pay_rate` decimal(10,2) NOT NULL,
  `image_url` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `rank_name` (`rank_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO `ranks` (`rank_name`, `min_hours`, `pay_rate`) VALUES
('Cadet', 0.00, 15.00),
('Junior First Officer', 50.00, 25.00),
('Senior First Officer', 150.00, 40.00),
('Captain', 500.00, 70.00),
('Senior Captain', 1000.00, 90.00);

-- --------------------------------------------------------

--
-- Table structure for table `pilots`
--

CREATE TABLE `pilots` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL,
  `rank` varchar(50) DEFAULT 'Cadet',
  `current_base` char(4) NOT NULL DEFAULT 'SBGR',
  `total_hours` decimal(10,2) DEFAULT 0.00,
  `balance` decimal(15,2) DEFAULT 0.00,
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`),
  KEY `rank` (`rank`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO `pilots` (`user_id`, `name`, `rank`, `current_base`, `total_hours`, `balance`) VALUES
(2, 'Commander Shepard', 'Senior Captain', 'SBGR', 1500.50, 25000.00),
(3, 'Maverick Mitchell', 'Senior Captain', 'KJFK', 3200.00, 45000.00);

-- --------------------------------------------------------

--
-- Table structure for table `fleet`
--

CREATE TABLE `fleet` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `icao_code` varchar(10) NOT NULL,
  `registration` varchar(20) NOT NULL,
  `fullname` varchar(100) NOT NULL,
  `cruise_speed` int(11) NOT NULL,
  `current_icao` char(4) DEFAULT 'SBGR',
  `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `registration` (`registration`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------

--
-- Table structure for table `flights_master`
--

CREATE TABLE `flights_master` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `flight_number` varchar(10) NOT NULL,
  `aircraft_id` int(11) DEFAULT NULL,
  `dep_icao` char(4) NOT NULL,
  `arr_icao` char(4) NOT NULL,
  `dep_time` time NOT NULL,
  `arr_time` time NOT NULL,
  `aircraft_type` varchar(20) NOT NULL,
  `duration_minutes` int(11) NOT NULL,
  `max_pax` int(11) DEFAULT 180,
  `route` text DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `dep_icao` (`dep_icao`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO `flights_master` (`flight_number`, `dep_icao`, `arr_icao`, `dep_time`, `arr_time`, `aircraft_type`, `duration_minutes`, `max_pax`) VALUES
('VA101', 'SBGR', 'KMIA', '09:00:00', '17:00:00', 'B777', 480, 350),
('VA102', 'KMIA', 'SBGR', '19:00:00', '03:00:00', 'B777', 480, 350),
('VA201', 'SBGR', 'SBRJ', '08:30:00', '09:15:00', 'A320', 45, 180),
('VA202', 'SBRJ', 'SBGR', '10:30:00', '11:15:00', 'A320', 45, 180),
('VA301', 'KJFK', 'EGLL', '18:00:00', '01:00:00', 'B787', 420, 250),
('VA302', 'EGLL', 'KJFK', '12:00:00', '20:00:00', 'B787', 480, 250);

-- --------------------------------------------------------

--
-- Table structure for table `roster_assignments`
--

CREATE TABLE `roster_assignments` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `pilot_id` int(11) NOT NULL,
  `flight_id` int(11) NOT NULL,
  `flight_date` date NOT NULL,
  `status` enum('Suggested','Accepted','Rejected','Flown') NOT NULL DEFAULT 'Suggested',
  `assigned_at` timestamp DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `pilot_id` (`pilot_id`),
  KEY `flight_id` (`flight_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------

--
-- Table structure for table `pilot_preferences`
--

CREATE TABLE `pilot_preferences` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `pilot_id` int(11) NOT NULL,
  `day_of_week` tinyint(1) NOT NULL COMMENT '0=Sunday...6=Saturday',
  `start_time` time NOT NULL,
  `end_time` time NOT NULL,
  `max_daily_hours` decimal(4,2) NOT NULL DEFAULT 8.00,
  PRIMARY KEY (`id`),
  KEY `pilot_id` (`pilot_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------

--
-- Table structure for table `pilot_aircraft_prefs`
--

CREATE TABLE `pilot_aircraft_prefs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `pilot_id` int(11) NOT NULL,
  `aircraft_type` varchar(20) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `pilot_id` (`pilot_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------

--
-- Table structure for table `flight_reports`
--

CREATE TABLE `flight_reports` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `pilot_id` int(11) NOT NULL,
  `roster_id` int(11) NOT NULL,
  `flight_time` decimal(10,2) NOT NULL,
  `fuel_used` decimal(10,2) NOT NULL,
  `landing_rate` int(11) NOT NULL,
  `pax` int(11) DEFAULT 0,
  `revenue` decimal(15,2) DEFAULT 0.00,
  `comments` text DEFAULT NULL,
  `incidents` text DEFAULT NULL,
  `status` enum('Pending','Approved','Rejected') NOT NULL DEFAULT 'Pending',
  `submitted_at` timestamp DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `pilot_id` (`pilot_id`),
  KEY `roster_id` (`roster_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------

--
-- Table structure for table `expense_venues`
--

CREATE TABLE `expense_venues` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `type` enum('HOTEL','FOOD') NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO `expense_venues` (`name`, `type`) VALUES
('Blue Star Hotel', 'HOTEL'),
('Sky Rest', 'FOOD'),
('Aviation Bistro', 'FOOD'),
('Cloud Inn', 'HOTEL');

-- --------------------------------------------------------

--
-- Table structure for table `system_settings`
--

CREATE TABLE `system_settings` (
  `setting_key` varchar(50) NOT NULL,
  `setting_value` text DEFAULT NULL,
  PRIMARY KEY (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO `system_settings` (`setting_key`, `setting_value`) VALUES
('va_name', 'SkyCrew Virtual Airline'),
('va_callsign', 'SKY'),
('va_logo_url', ''),
('daily_idle_cost', '150.00'),
('currency_symbol', 'R$'),
('simbrief_username', ''),
('simbrief_api_key', ''),
('fleet_registration_prefixes', 'PR,PT,PP,PS,PU');

-- --------------------------------------------------------

--
-- Constraints
--

ALTER TABLE `pilots`
  ADD CONSTRAINT `fk_pilot_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

ALTER TABLE `roster_assignments`
  ADD CONSTRAINT `fk_roster_pilot` FOREIGN KEY (`pilot_id`) REFERENCES `pilots` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_roster_flight` FOREIGN KEY (`flight_id`) REFERENCES `flights_master` (`id`) ON DELETE CASCADE;

ALTER TABLE `pilot_preferences`
  ADD CONSTRAINT `fk_pilot_pref` FOREIGN KEY (`pilot_id`) REFERENCES `pilots` (`id`) ON DELETE CASCADE;

ALTER TABLE `pilot_aircraft_prefs`
  ADD CONSTRAINT `fk_pilot_ac_pref` FOREIGN KEY (`pilot_id`) REFERENCES `pilots` (`id`) ON DELETE CASCADE;

ALTER TABLE `flight_reports`
  ADD CONSTRAINT `fk_report_pilot` FOREIGN KEY (`pilot_id`) REFERENCES `pilots` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_report_roster` FOREIGN KEY (`roster_id`) REFERENCES `roster_assignments` (`id`) ON DELETE CASCADE;

COMMIT;
