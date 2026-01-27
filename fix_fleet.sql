-- Create Fleet Table
CREATE TABLE IF NOT EXISTS `fleet` (
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

-- Insert some fleet data
INSERT INTO `fleet` (`registration`, `icao_code`, `name`, `current_icao`, `status`) VALUES
('PR-KFY', 'B777', 'The Flagship', 'SBGR', 'Available'),
('PR-SKY', 'B787', 'Dreamliner', 'KJFK', 'Available'),
('PT-JGS', 'A320', 'City Hopper', 'SBRJ', 'Available');

-- Update flights_master
ALTER TABLE `flights_master`
  ADD COLUMN `aircraft_id` INT DEFAULT NULL,
  ADD COLUMN `route` TEXT DEFAULT NULL;

-- Link existing flights to fleet (approximate matching based on aircraft_type)
UPDATE `flights_master` SET `aircraft_id` = (SELECT id FROM fleet WHERE icao_code = 'B777' LIMIT 1) WHERE aircraft_type = 'B777';
UPDATE `flights_master` SET `aircraft_id` = (SELECT id FROM fleet WHERE icao_code = 'B787' LIMIT 1) WHERE aircraft_type = 'B787';
UPDATE `flights_master` SET `aircraft_id` = (SELECT id FROM fleet WHERE icao_code = 'A320' LIMIT 1) WHERE aircraft_type = 'A320';

-- Add Foreign Key
ALTER TABLE `flights_master`
  ADD CONSTRAINT `fk_flight_aircraft` FOREIGN KEY (`aircraft_id`) REFERENCES `fleet` (`id`) ON DELETE SET NULL;
