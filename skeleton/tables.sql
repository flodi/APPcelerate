#

CREATE TABLE `countries` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `nome` varchar(50) NOT NULL,
  `locale` varchar(2) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=9 DEFAULT CHARSET=latin1;

CREATE TABLE `languages` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `id_country` int(10) unsigned NOT NULL,
  `locale` varchar(2) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_languages` (`id_country`),
  CONSTRAINT `fk_languages` FOREIGN KEY (`id_country`) REFERENCES `countries` (`id`) ON DELETE NO ACTION ON UPDATE NO ACTION
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=latin1;

CREATE TABLE `strings` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `token` varchar(50) NOT NULL,
  `string` text NOT NULL,
  `id_language` int(10) unsigned NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_strings` (`id_language`),
  CONSTRAINT `fk_strings` FOREIGN KEY (`id_language`) REFERENCES `languages` (`id`) ON DELETE NO ACTION ON UPDATE NO ACTION
) ENGINE=InnoDB AUTO_INCREMENT=22 DEFAULT CHARSET=latin1;

CREATE TABLE `users` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `id_language` int(10) unsigned NOT NULL,
  `login` char(15) NOT NULL,
  `pwd` char(15) NOT NULL,
  `created` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `last_access` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `app` varchar(2) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_accessi` (`id_language`),
  CONSTRAINT `fk_accessi` FOREIGN KEY (`id_language`) REFERENCES `languages` (`id`) ON DELETE NO ACTION ON UPDATE NO ACTION
) ENGINE=InnoDB AUTO_INCREMENT=126 DEFAULT CHARSET=latin1;

SET SESSION SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";

INSERT INTO `countries` (id, nome, locale) VALUES (0,"Italia","IT");

INSERT INTO `languages` (id_country, locale) VALUES (0,"IT");
