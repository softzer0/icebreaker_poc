SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET AUTOCOMMIT = 0;
START TRANSACTION;
SET time_zone = "+00:00";

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;


CREATE TABLE `main` (
  `name` varchar(34) CHARACTER SET utf8 COLLATE utf8_unicode_ci NOT NULL,
  `coords` point NOT NULL,
  `alt` float(4,1) NOT NULL,
  `init_coords` point NOT NULL,
  `init_alt` float(4,1) NOT NULL,
  `token` char(8) NOT NULL,
  `img_name` char(8) NOT NULL,
  `img_refreshed` int(11) NOT NULL DEFAULT '0',
  `to_refresh` tinyint(1) NOT NULL DEFAULT '0',
  `active` char(8) DEFAULT NULL,
  `img_size` varchar(7) NOT NULL,
  `img_md5` char(32) NOT NULL,
  `created` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `accessed` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `device_id` char(16) NOT NULL
) ENGINE=MyISAM DEFAULT CHARSET=ascii;

CREATE TABLE `nudge` (
  `from` char(8) NOT NULL,
  `to` char(8) NOT NULL,
  `sent` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `not_delivered` tinyint(11) NOT NULL DEFAULT '1',
  `answer` tinyint(1) DEFAULT NULL
) ENGINE=MyISAM DEFAULT CHARSET=ascii;


ALTER TABLE `main`
  ADD UNIQUE KEY `img` (`img_md5`,`img_size`) USING BTREE,
  ADD SPATIAL KEY `coords` (`coords`),
  ADD SPATIAL KEY `init_coords` (`init_coords`);

ALTER TABLE `nudge`
  ADD UNIQUE KEY `unique` (`from`,`to`) USING BTREE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
