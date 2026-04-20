-- Lingunan Fitness Gym — Database Backup
-- Generated: 2026-04-15 16:27:11
-- Filter: date = 2026-04-07

SET FOREIGN_KEY_CHECKS=0;

-- Table: members
DROP TABLE IF EXISTS `members`;
CREATE TABLE `members` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(10) unsigned DEFAULT NULL,
  `first_name` varchar(50) NOT NULL,
  `last_name` varchar(50) NOT NULL,
  `phone` varchar(20) DEFAULT NULL,
  `address` varchar(255) DEFAULT NULL,
  `type` varchar(50) DEFAULT NULL,
  `membership_start` date DEFAULT NULL,
  `membership_end` date DEFAULT NULL,
  `password` varchar(255) NOT NULL,
  `username` varchar(50) NOT NULL,
  `gmail` varchar(100) NOT NULL,
  `RFID` varchar(50) DEFAULT NULL,
  `Joined_Date` date DEFAULT curdate(),
  `credit` decimal(10,2) DEFAULT 0.00,
  `plan_months` int(11) DEFAULT NULL,
  `membership_expiry` date DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`),
  CONSTRAINT `members_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=19 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- (no rows match filter for members)

-- Table: entry_logs
DROP TABLE IF EXISTS `entry_logs`;
CREATE TABLE `entry_logs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `member_id` int(11) DEFAULT NULL,
  `member_name` varchar(100) NOT NULL DEFAULT 'Walk-in',
  `entry_type` varchar(20) NOT NULL DEFAULT 'session',
  `amount_charged` decimal(10,2) NOT NULL DEFAULT 0.00,
  `payment_method` varchar(20) NOT NULL DEFAULT 'cash',
  `entry_time` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- (no rows match filter for entry_logs)

-- Table: sales
DROP TABLE IF EXISTS `sales`;
CREATE TABLE `sales` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `product_id` int(11) NOT NULL,
  `product_name` varchar(100) NOT NULL,
  `qty_sold` int(11) NOT NULL,
  `unit_price` decimal(10,2) NOT NULL,
  `total` decimal(10,2) NOT NULL,
  `sold_at` datetime DEFAULT current_timestamp(),
  `payment_method` varchar(20) DEFAULT 'cash',
  `member_name` varchar(100) DEFAULT NULL,
  `transacted_by` varchar(100) DEFAULT NULL,
  `transaction_id` varchar(32) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=30 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- (no rows match filter for sales)

-- Table: users
DROP TABLE IF EXISTS `users`;
CREATE TABLE `users` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `username` varchar(50) NOT NULL,
  `password` varchar(255) NOT NULL,
  `email` varchar(100) NOT NULL,
  `role` enum('super_admin','staff') DEFAULT 'staff',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `status` varchar(10) NOT NULL DEFAULT 'active',
  PRIMARY KEY (`id`),
  UNIQUE KEY `username` (`username`),
  UNIQUE KEY `email` (`email`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO `users` (`id`, `username`, `password`, `email`, `role`, `created_at`, `status`) VALUES
('1', 'admin', '$2y$10$PEsXIWlBFf3dqwJnx8Z4..ucCQKz0K8JxsQpwG6CxQKbb4trcnvn6', 'admin@example.com', 'super_admin', '2026-04-12 16:43:31', 'active'),
('3', 'asd', '$2y$10$470XpC7qnO/sFKFU9Pmh1eK53s3NXeL1lJpjDhRHBIZijl8VCggda', 'asd@example.com', 'staff', '2026-04-13 19:46:38', 'active');

-- Table: products
DROP TABLE IF EXISTS `products`;
CREATE TABLE `products` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `product_name` varchar(100) NOT NULL,
  `quantity` int(11) NOT NULL,
  `price` decimal(10,2) NOT NULL,
  `img` varchar(255) DEFAULT NULL,
  `date_stocked` date DEFAULT curdate(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=8 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO `products` (`id`, `product_name`, `quantity`, `price`, `img`, `date_stocked`) VALUES
('6', 'shabu', '88', '100.00', 'prod_1776262814_8334df77.jpg', '2026-04-15'),
('7', 'shake', '0', '10.00', '', '2026-04-15');

-- Table: blocked_rfids
DROP TABLE IF EXISTS `blocked_rfids`;
CREATE TABLE `blocked_rfids` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `rfid` varchar(100) NOT NULL,
  `member_id` int(11) DEFAULT NULL,
  `blocked_at` datetime DEFAULT current_timestamp(),
  `reason` varchar(100) DEFAULT 'lost',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- (no rows match filter for blocked_rfids)

SET FOREIGN_KEY_CHECKS=1;
