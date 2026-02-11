DROP TABLE IF EXISTS `expense_venues`;
CREATE TABLE `expense_venues` (
  `id` int NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `type` enum('HOTEL','FOOD') NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

INSERT INTO `expense_venues` VALUES 
('1', 'Blue Star Hotel', 'HOTEL'),
('2', 'Sky Rest', 'FOOD'),
('3', 'Aviation Bistro', 'FOOD'),
('4', 'Cloud Inn', 'HOTEL');

DROP TABLE IF EXISTS `pilot_aircraft_prefs`;
CREATE TABLE `pilot_aircraft_prefs` (
  `id` int NOT NULL AUTO_INCREMENT,
  `pilot_id` int NOT NULL,
  `aircraft_type` varchar(20) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `pilot_id` (`pilot_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

DROP TABLE IF EXISTS `pilot_preferences`;
CREATE TABLE `pilot_preferences` (
  `id` int NOT NULL AUTO_INCREMENT,
  `pilot_id` int NOT NULL,
  `day_of_week` tinyint(1) NOT NULL COMMENT '0=Sunday...6=Saturday',
  `start_time` time NOT NULL,
  `end_time` time NOT NULL,
  `max_daily_hours` decimal(4,2) NOT NULL DEFAULT '8.00',
  PRIMARY KEY (`id`),
  KEY `pilot_id` (`pilot_id`),
  CONSTRAINT `fk_pilot_pref` FOREIGN KEY (`pilot_id`) REFERENCES `pilots` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

DROP TABLE IF EXISTS `pilots`;
CREATE TABLE `pilots` (
  `id` int NOT NULL AUTO_INCREMENT,
  `user_id` int NOT NULL,
  `name` varchar(100) NOT NULL,
  `rank` varchar(50) DEFAULT 'Cadet',
  `current_base` char(4) NOT NULL DEFAULT 'SBGR',
  `total_hours` decimal(10,2) DEFAULT '0.00',
  `balance` decimal(15,2) DEFAULT '0.00',
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`),
  CONSTRAINT `fk_pilot_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

DROP TABLE IF EXISTS `ranks`;
CREATE TABLE `ranks` (
  `id` int NOT NULL AUTO_INCREMENT,
  `rank_name` varchar(50) NOT NULL,
  `min_hours` decimal(10,2) NOT NULL,
  `pay_rate` decimal(10,2) NOT NULL,
  `image_url` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `rank_name` (`rank_name`)
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

INSERT INTO `ranks` VALUES 
('1', 'Cadet', '0.00', '15.00', NULL),
('2', 'Junior First Officer', '50.00', '25.00', NULL),
('3', 'Senior First Officer', '150.00', '40.00', NULL),
('4', 'Captain', '500.00', '70.00', NULL),
('5', 'Senior Captain', '1000.00', '90.00', NULL);

DROP TABLE IF EXISTS `system_settings`;
CREATE TABLE `system_settings` (
  `setting_key` varchar(50) NOT NULL,
  `setting_value` text,
  PRIMARY KEY (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

INSERT INTO `system_settings` VALUES 
('currency_symbol', 'R$'),
('daily_idle_cost', '150.00'),
('fleet_registration_prefixes', 'PR,PT,PP,PS,PU'),
('simbrief_api_key', 'AbGsxplL4TmWKj9Yp0fCb2Rbu7mKmdQR'),
('simbrief_username', 'andersonguilher'),
('va_callsign', 'kfy'),
('va_logo_url', 'https://www.kafly.com.br/dash/assets/logo.png'),
('va_name', 'KAFLY LINHAS AÉREAS VIRTUAIS');

DROP TABLE IF EXISTS `users`;
CREATE TABLE `users` (
  `id` int NOT NULL AUTO_INCREMENT,
  `email` varchar(150) NOT NULL,
  `password` varchar(255) NOT NULL,
  `role` enum('admin','pilot') NOT NULL DEFAULT 'pilot',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `email` (`email`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

INSERT INTO `users` VALUES 
('1', 'admin@skycrew.com', '$2y$10$DJHPYPiwBO7sqUPLW0MCzeyZ0MsnvHl1HpVvQCmBxkuFtRP.gTb.i', 'admin', '2026-01-27 19:10:16'),
('2', 'shepard@skycrew.com', '$2y$10$DJHPYPiwBO7sqUPLW0MCzeyZ0MsnvHl1HpVvQCmBxkuFtRP.gTb.i', 'pilot', '2026-01-27 19:10:16'),
('3', 'maverick@skycrew.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'pilot', '2026-01-27 19:10:16');

DROP TABLE IF EXISTS `fleet`;
CREATE TABLE `fleet` (
  `id` int NOT NULL AUTO_INCREMENT,
  `icao_code` varchar(10) NOT NULL,
  `registration` varchar(20) NOT NULL,
  `fullname` varchar(100) NOT NULL,
  `cruise_speed` int NOT NULL,
  `current_icao` char(4) DEFAULT 'SBGR',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `registration` (`registration`)
) ENGINE=InnoDB AUTO_INCREMENT=13 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

DROP TABLE IF EXISTS `flights_master`;
CREATE TABLE `flights_master` (
  `id` int NOT NULL AUTO_INCREMENT,
  `flight_number` varchar(10) NOT NULL,
  `aircraft_id` int DEFAULT NULL,
  `dep_icao` char(4) NOT NULL,
  `arr_icao` char(4) NOT NULL,
  `dep_time` time NOT NULL,
  `arr_time` time NOT NULL,
  `aircraft_type` varchar(20) NOT NULL,
  `duration_minutes` int NOT NULL,
  `max_pax` int DEFAULT '180',
  `route` text,
  PRIMARY KEY (`id`),
  KEY `dep_icao` (`dep_icao`)
) ENGINE=InnoDB AUTO_INCREMENT=31 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

DROP TABLE IF EXISTS `roster_assignments`;
CREATE TABLE `roster_assignments` (
  `id` int NOT NULL AUTO_INCREMENT,
  `pilot_id` int NOT NULL,
  `flight_id` int NOT NULL,
  `flight_date` date NOT NULL,
  `status` enum('Suggested','Accepted','Rejected','Flown') NOT NULL DEFAULT 'Suggested',
  `assigned_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `pilot_id` (`pilot_id`),
  KEY `flight_id` (`flight_id`),
  CONSTRAINT `fk_roster_flight` FOREIGN KEY (`flight_id`) REFERENCES `flights_master` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_roster_pilot` FOREIGN KEY (`pilot_id`) REFERENCES `pilots` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

DROP TABLE IF EXISTS `flight_reports`;
CREATE TABLE `flight_reports` (
  `id` int NOT NULL AUTO_INCREMENT,
  `pilot_id` int NOT NULL,
  `roster_id` int NOT NULL,
  `flight_time` decimal(10,2) NOT NULL,
  `fuel_used` decimal(10,2) NOT NULL,
  `landing_rate` int NOT NULL,
  `pax` int DEFAULT '0',
  `revenue` decimal(15,2) DEFAULT '0.00',
  `comments` text,
  `incidents` text,
  `status` enum('Pending','Approved','Rejected') NOT NULL DEFAULT 'Pending',
  `submitted_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `pilot_id` (`pilot_id`),
  KEY `roster_id` (`roster_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

