-- Database Draft V2 - Complete Structure with Auth

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";

-- Disable foreign key checks for drop
SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS `roster_assignments`;
DROP TABLE IF EXISTS `pilot_preferences`;
DROP TABLE IF EXISTS `pilots`;
DROP TABLE IF EXISTS `users`;
DROP TABLE IF EXISTS `flight_reports`;
DROP TABLE IF EXISTS `flights_master`;
DROP TABLE IF EXISTS `fleet`;

SET FOREIGN_KEY_CHECKS = 1;

--
-- Table structure for table `system_settings`
--

CREATE TABLE `system_settings` (
  `setting_key` varchar(50) NOT NULL,
  `setting_value` text,
  PRIMARY KEY (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO `system_settings` (`setting_key`, `setting_value`) VALUES
('va_name', 'SkyCrew Virtual Airline'),
('va_callsign', 'SKY'),
('currency_symbol', '$'),
('daily_idle_cost', '150.00'),
('fleet_registration_prefixes', 'PR,PT,PP,PS,PU');

--
-- Table structure for table `users` (Authentication)
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
(1, 'admin@skycrew.com', '$2y$10$WLZOPeSOrSlq5ZPsfMWXu.g7UOIw5xkDqqqvcNlTUwLGEN15y.Ve.', 'admin'),
(2, 'shepard@skycrew.com', '$2y$10$WLZOPeSOrSlq5ZPsfMWXu.g7UOIw5xkDqqqvcNlTUwLGEN15y.Ve.', 'pilot'),
(3, 'maverick@skycrew.com', '$2y$10$WLZOPeSOrSlq5ZPsfMWXu.g7UOIw5xkDqqqvcNlTUwLGEN15y.Ve.', 'pilot');

-- --------------------------------------------------------

--
-- Table structure for table `pilots`
--

CREATE TABLE `pilots` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL,
  `current_base` char(4) NOT NULL DEFAULT 'SBGR',
  `total_hours` decimal(10,2) DEFAULT 0.00,
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO `pilots` (`user_id`, `name`, `current_base`, `total_hours`) VALUES
(2, 'Commander Shepard', 'SBGR', 1500.50),
(3, 'Maverick Mitchell', 'KJFK', 3200.00);

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

INSERT INTO `pilot_preferences` (`pilot_id`, `day_of_week`, `start_time`, `end_time`, `max_daily_hours`) VALUES
(1, 1, '08:00:00', '22:00:00', 8.00),
(1, 3, '10:00:00', '20:00:00', 6.00),
(1, 5, '06:00:00', '18:00:00', 10.00);

-- --------------------------------------------------------

--
-- Table structure for table `fleet`
--

CREATE TABLE `fleet` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `registration` varchar(10) NOT NULL,
  `icao_code` varchar(6) NOT NULL,
  `name` varchar(50) DEFAULT NULL,
  `status` enum('Available','InFlight','Maintenance') NOT NULL DEFAULT 'Available',
  `current_icao` char(4) NOT NULL DEFAULT 'SBGR',
  `total_hours` decimal(10,2) DEFAULT 0.00,
  `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `registration` (`registration`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO `fleet` (`registration`, `icao_code`, `name`, `current_icao`, `status`) VALUES
('PR-KFY', 'B777', 'The Flagship', 'SBGR', 'Available'),
('PR-SKY', 'B787', 'Dreamliner', 'KJFK', 'Available'),
('PT-JGS', 'A320', 'City Hopper', 'SBRJ', 'Available');

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
  `max_pax` int(11) NOT NULL DEFAULT 180,
  `route` text DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `dep_icao` (`dep_icao`),
  KEY `aircraft_id` (`aircraft_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO `flights_master` (`flight_number`, `dep_icao`, `arr_icao`, `dep_time`, `arr_time`, `aircraft_type`, `duration_minutes`, `max_pax`, `aircraft_id`) VALUES
('VA101', 'SBGR', 'KMIA', '09:00:00', '17:00:00', 'B777', 480, 350, 1),
('VA102', 'KMIA', 'SBGR', '19:00:00', '03:00:00', 'B777', 480, 350, 1),
('VA201', 'SBGR', 'SBRJ', '08:30:00', '09:15:00', 'A320', 45, 174, 3),
('VA202', 'SBRJ', 'SBGR', '10:30:00', '11:15:00', 'A320', 45, 174, 3),
('VA301', 'KJFK', 'EGLL', '18:00:00', '01:00:00', 'B787', 420, 250, 2),
('VA302', 'EGLL', 'KJFK', '12:00:00', '20:00:00', 'B787', 480, 250, 2);

-- --------------------------------------------------------

--
-- Table structure for table `flight_reports`
--

CREATE TABLE `flight_reports` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `roster_id` int(11) NOT NULL,
  `pilot_id` int(11) NOT NULL,
  `flight_time_minutes` int(11) NOT NULL,
  `fuel_used` decimal(10,2) NOT NULL,
  `distance_flown` int(11) NOT NULL,
  `landing_rate` int(11) DEFAULT NULL,
  `status` enum('Pending','Approved','Rejected') NOT NULL DEFAULT 'Pending',
  `revenue` decimal(10,2) DEFAULT 0.00,
  `pax` int(11) DEFAULT 0,
  `submitted_at` timestamp DEFAULT CURRENT_TIMESTAMP,
  `remarks` text,
  PRIMARY KEY (`id`),
  KEY `roster_id` (`roster_id`),
  KEY `pilot_id` (`pilot_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

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

--
-- Constraints
--

ALTER TABLE `pilots`
  ADD CONSTRAINT `fk_pilot_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

ALTER TABLE `pilot_preferences`
  ADD CONSTRAINT `fk_pilot_pref` FOREIGN KEY (`pilot_id`) REFERENCES `pilots` (`id`) ON DELETE CASCADE;

ALTER TABLE `flights_master`
  ADD CONSTRAINT `fk_flight_aircraft` FOREIGN KEY (`aircraft_id`) REFERENCES `fleet` (`id`) ON DELETE SET NULL;
  
ALTER TABLE `roster_assignments`
  ADD CONSTRAINT `fk_roster_pilot` FOREIGN KEY (`pilot_id`) REFERENCES `pilots` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_roster_flight` FOREIGN KEY (`flight_id`) REFERENCES `flights_master` (`id`) ON DELETE CASCADE;
  
ALTER TABLE `flight_reports`
  ADD CONSTRAINT `fk_report_roster` FOREIGN KEY (`roster_id`) REFERENCES `roster_assignments` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_report_pilot` FOREIGN KEY (`pilot_id`) REFERENCES `pilots` (`id`) ON DELETE CASCADE;

COMMIT;
