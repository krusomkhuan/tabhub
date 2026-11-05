-- ระบบจัดตารางเรียน–ตารางสอน : โครงสร้างฐานข้อมูล MariaDB
-- นำเข้าใน phpMyAdmin หลังเลือกฐานข้อมูลแล้ว หรือรัน php scripts/install.php

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `Settings` (
  `_seq` BIGINT NOT NULL AUTO_INCREMENT,
  `key` VARCHAR(64) NULL,
  `value` TEXT NULL,
  PRIMARY KEY (`_seq`),
  KEY `ix_key` (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `Semesters` (
  `_seq` BIGINT NOT NULL AUTO_INCREMENT,
  `id` VARCHAR(64) NULL,
  `year` TEXT NULL,
  `term` TEXT NULL,
  `label` TEXT NULL,
  `is_default` TEXT NULL,
  PRIMARY KEY (`_seq`),
  KEY `ix_id` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `Departments` (
  `_seq` BIGINT NOT NULL AUTO_INCREMENT,
  `id` VARCHAR(64) NULL,
  `name` TEXT NULL,
  `sort` TEXT NULL,
  PRIMARY KEY (`_seq`),
  KEY `ix_id` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `Majors` (
  `_seq` BIGINT NOT NULL AUTO_INCREMENT,
  `id` VARCHAR(64) NULL,
  `department_id` VARCHAR(64) NULL,
  `name` TEXT NULL,
  `level` TEXT NULL,
  `sort` TEXT NULL,
  PRIMARY KEY (`_seq`),
  KEY `ix_id` (`id`),
  KEY `ix_department_id` (`department_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `Teachers` (
  `_seq` BIGINT NOT NULL AUTO_INCREMENT,
  `id` VARCHAR(64) NULL,
  `code` TEXT NULL,
  `name` TEXT NULL,
  `department_id` VARCHAR(64) NULL,
  `major_id` VARCHAR(64) NULL,
  `position` TEXT NULL,
  `employment` TEXT NULL,
  `is_dept_head` TEXT NULL,
  `is_unit_head` TEXT NULL,
  `service_start` TEXT NULL,
  `award_bonus` TEXT NULL,
  `award_note` TEXT NULL,
  `ot_enabled` TEXT NULL,
  `ot_scheme_ids` TEXT NULL,
  `education` TEXT NULL,
  `special_duty` TEXT NULL,
  `max_per_day` TEXT NULL,
  `unavailable` TEXT NULL,
  `email` TEXT NULL,
  `tab_id` VARCHAR(64) NULL,
  PRIMARY KEY (`_seq`),
  KEY `ix_id` (`id`),
  KEY `ix_department_id` (`department_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `Buildings` (
  `_seq` BIGINT NOT NULL AUTO_INCREMENT,
  `id` VARCHAR(64) NULL,
  `name` TEXT NULL,
  PRIMARY KEY (`_seq`),
  KEY `ix_id` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `Rooms` (
  `_seq` BIGINT NOT NULL AUTO_INCREMENT,
  `id` VARCHAR(64) NULL,
  `building_id` VARCHAR(64) NULL,
  `name` TEXT NULL,
  `type` TEXT NULL,
  `capacity` TEXT NULL,
  `shareable` TEXT NULL,
  `tab_id` VARCHAR(64) NULL,
  PRIMARY KEY (`_seq`),
  KEY `ix_id` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `Courses` (
  `_seq` BIGINT NOT NULL AUTO_INCREMENT,
  `id` VARCHAR(64) NULL,
  `code` TEXT NULL,
  `name` TEXT NULL,
  `theory` TEXT NULL,
  `practice` TEXT NULL,
  `credits` TEXT NULL,
  `level` TEXT NULL,
  `course_type` TEXT NULL,
  `kind` TEXT NULL,
  `subject_group` TEXT NULL,
  PRIMARY KEY (`_seq`),
  KEY `ix_id` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `Groups` (
  `_seq` BIGINT NOT NULL AUTO_INCREMENT,
  `id` VARCHAR(64) NULL,
  `code` TEXT NULL,
  `name` TEXT NULL,
  `level` TEXT NULL,
  `year_level` TEXT NULL,
  `major_id` VARCHAR(64) NULL,
  `department_id` VARCHAR(64) NULL,
  `students` TEXT NULL,
  `room_id` VARCHAR(64) NULL,
  `advisor_id` VARCHAR(64) NULL,
  `student_code` TEXT NULL,
  `tab_id` VARCHAR(64) NULL,
  PRIMARY KEY (`_seq`),
  KEY `ix_id` (`id`),
  KEY `ix_department_id` (`department_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `Plans` (
  `_seq` BIGINT NOT NULL AUTO_INCREMENT,
  `id` VARCHAR(64) NULL,
  `semester_id` VARCHAR(64) NULL,
  `group_id` VARCHAR(64) NULL,
  `course_id` VARCHAR(64) NULL,
  `teacher_ids` TEXT NULL,
  `room_id` VARCHAR(64) NULL,
  `periods` TEXT NULL,
  `split` TEXT NULL,
  `joint_key` TEXT NULL,
  `workplace` TEXT NULL,
  `note` TEXT NULL,
  PRIMARY KEY (`_seq`),
  KEY `ix_id` (`id`),
  KEY `ix_semester_id` (`semester_id`),
  KEY `ix_group_id` (`group_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `Sessions` (
  `_seq` BIGINT NOT NULL AUTO_INCREMENT,
  `id` VARCHAR(64) NULL,
  `semester_id` VARCHAR(64) NULL,
  `plan_id` VARCHAR(64) NULL,
  `group_id` VARCHAR(64) NULL,
  `course_id` VARCHAR(64) NULL,
  `teacher_ids` TEXT NULL,
  `room_id` VARCHAR(64) NULL,
  `day` TEXT NULL,
  `start` TEXT NULL,
  `length` TEXT NULL,
  `locked` TEXT NULL,
  `updated_by` TEXT NULL,
  `updated_at` TEXT NULL,
  PRIMARY KEY (`_seq`),
  KEY `ix_id` (`id`),
  KEY `ix_semester_id` (`semester_id`),
  KEY `ix_plan_id` (`plan_id`),
  KEY `ix_group_id` (`group_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `LockedSlots` (
  `_seq` BIGINT NOT NULL AUTO_INCREMENT,
  `id` VARCHAR(64) NULL,
  `day` TEXT NULL,
  `period` TEXT NULL,
  `label` TEXT NULL,
  PRIMARY KEY (`_seq`),
  KEY `ix_id` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `Windows` (
  `_seq` BIGINT NOT NULL AUTO_INCREMENT,
  `id` VARCHAR(64) NULL,
  `semester_id` VARCHAR(64) NULL,
  `department_id` VARCHAR(64) NULL,
  `start_date` TEXT NULL,
  `end_date` TEXT NULL,
  `sort` TEXT NULL,
  PRIMARY KEY (`_seq`),
  KEY `ix_id` (`id`),
  KEY `ix_semester_id` (`semester_id`),
  KEY `ix_department_id` (`department_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `Users` (
  `_seq` BIGINT NOT NULL AUTO_INCREMENT,
  `id` VARCHAR(64) NULL,
  `username` TEXT NULL,
  `name` TEXT NULL,
  `role` TEXT NULL,
  `department_id` VARCHAR(64) NULL,
  `teacher_id` VARCHAR(64) NULL,
  `email` TEXT NULL,
  `salt` TEXT NULL,
  `pass_hash` TEXT NULL,
  `active` TEXT NULL,
  PRIMARY KEY (`_seq`),
  KEY `ix_id` (`id`),
  KEY `ix_department_id` (`department_id`),
  KEY `ix_teacher_id` (`teacher_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `Log` (
  `_seq` BIGINT NOT NULL AUTO_INCREMENT,
  `time` TEXT NULL,
  `user` TEXT NULL,
  `action` TEXT NULL,
  `detail` TEXT NULL,
  PRIMARY KEY (`_seq`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `OtSchemes` (
  `_seq` BIGINT NOT NULL AUTO_INCREMENT,
  `id` VARCHAR(64) NULL,
  `name` TEXT NULL,
  `group` TEXT NULL,
  `total_cap` TEXT NULL,
  `cap_method` TEXT NULL,
  `sort` TEXT NULL,
  `vc_enabled` TEXT NULL,
  `vc_load` TEXT NULL,
  `vc_min` TEXT NULL,
  `vc_max` TEXT NULL,
  `vc_rate` TEXT NULL,
  `vs_enabled` TEXT NULL,
  `vs_load` TEXT NULL,
  `vs_min` TEXT NULL,
  `vs_max` TEXT NULL,
  `vs_rate` TEXT NULL,
  `bd_enabled` TEXT NULL,
  `bd_load` TEXT NULL,
  `bd_min` TEXT NULL,
  `bd_max` TEXT NULL,
  `bd_rate` TEXT NULL,
  `note` TEXT NULL,
  PRIMARY KEY (`_seq`),
  KEY `ix_id` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `OtPerHead` (
  `_seq` BIGINT NOT NULL AUTO_INCREMENT,
  `id` VARCHAR(64) NULL,
  `track` TEXT NULL,
  `course_kind` TEXT NULL,
  `subject_group` TEXT NULL,
  `below_students` TEXT NULL,
  `rate_per_head` TEXT NULL,
  PRIMARY KEY (`_seq`),
  KEY `ix_id` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `OtRounds` (
  `_seq` BIGINT NOT NULL AUTO_INCREMENT,
  `id` VARCHAR(64) NULL,
  `semester_id` VARCHAR(64) NULL,
  `name` TEXT NULL,
  `start_date` TEXT NULL,
  `end_date` TEXT NULL,
  PRIMARY KEY (`_seq`),
  KEY `ix_id` (`id`),
  KEY `ix_semester_id` (`semester_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `OtBlocked` (
  `_seq` BIGINT NOT NULL AUTO_INCREMENT,
  `id` VARCHAR(64) NULL,
  `name` TEXT NULL,
  `round_id` VARCHAR(64) NULL,
  `day` TEXT NULL,
  `start_date` TEXT NULL,
  `end_date` TEXT NULL,
  `period_from` TEXT NULL,
  `period_to` TEXT NULL,
  PRIMARY KEY (`_seq`),
  KEY `ix_id` (`id`),
  KEY `ix_round_id` (`round_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `OtAbsences` (
  `_seq` BIGINT NOT NULL AUTO_INCREMENT,
  `id` VARCHAR(64) NULL,
  `round_id` VARCHAR(64) NULL,
  `teacher_id` VARCHAR(64) NULL,
  `date` TEXT NULL,
  `period_from` TEXT NULL,
  `period_to` TEXT NULL,
  `type` TEXT NULL,
  `note` TEXT NULL,
  `created_by` TEXT NULL,
  PRIMARY KEY (`_seq`),
  KEY `ix_id` (`id`),
  KEY `ix_round_id` (`round_id`),
  KEY `ix_teacher_id` (`teacher_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `OtSubs` (
  `_seq` BIGINT NOT NULL AUTO_INCREMENT,
  `id` VARCHAR(64) NULL,
  `round_id` VARCHAR(64) NULL,
  `type` TEXT NULL,
  `reason` TEXT NULL,
  `original_teacher_id` VARCHAR(64) NULL,
  `teacher_id` VARCHAR(64) NULL,
  `session_id` VARCHAR(64) NULL,
  `group_id` VARCHAR(64) NULL,
  `course_id` VARCHAR(64) NULL,
  `original_date` TEXT NULL,
  `date` TEXT NULL,
  `start` TEXT NULL,
  `length` TEXT NULL,
  `report_due` TEXT NULL,
  `report_done` TEXT NULL,
  `report_link` TEXT NULL,
  `status` TEXT NULL,
  `note` TEXT NULL,
  `created_by` TEXT NULL,
  `created_at` TEXT NULL,
  PRIMARY KEY (`_seq`),
  KEY `ix_id` (`id`),
  KEY `ix_round_id` (`round_id`),
  KEY `ix_teacher_id` (`teacher_id`),
  KEY `ix_group_id` (`group_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `OtClaims` (
  `_seq` BIGINT NOT NULL AUTO_INCREMENT,
  `id` VARCHAR(64) NULL,
  `round_id` VARCHAR(64) NULL,
  `teacher_id` VARCHAR(64) NULL,
  `scheme_id` VARCHAR(64) NULL,
  `weeks` TEXT NULL,
  `status` TEXT NULL,
  `hours` TEXT NULL,
  `amount` TEXT NULL,
  `summary` TEXT NULL,
  `updated_by` TEXT NULL,
  `updated_at` TEXT NULL,
  `locked_by` TEXT NULL,
  `locked_at` TEXT NULL,
  PRIMARY KEY (`_seq`),
  KEY `ix_id` (`id`),
  KEY `ix_round_id` (`round_id`),
  KEY `ix_teacher_id` (`teacher_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `OtSubmissions` (
  `_seq` BIGINT NOT NULL AUTO_INCREMENT,
  `id` VARCHAR(64) NULL,
  `round_id` VARCHAR(64) NULL,
  `department_id` VARCHAR(64) NULL,
  `status` TEXT NULL,
  `note` TEXT NULL,
  `snapshot` TEXT NULL,
  `created_by` TEXT NULL,
  `created_at` TEXT NULL,
  PRIMARY KEY (`_seq`),
  KEY `ix_id` (`id`),
  KEY `ix_round_id` (`round_id`),
  KEY `ix_department_id` (`department_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `TeachReports` (
  `_seq` BIGINT NOT NULL AUTO_INCREMENT,
  `id` VARCHAR(64) NULL,
  `semester_id` VARCHAR(64) NULL,
  `teacher_id` VARCHAR(64) NULL,
  `session_id` VARCHAR(64) NULL,
  `group_id` VARCHAR(64) NULL,
  `course_id` VARCHAR(64) NULL,
  `date` TEXT NULL,
  `start` TEXT NULL,
  `length` TEXT NULL,
  `kind` TEXT NULL,
  `topic` TEXT NULL,
  `activity` TEXT NULL,
  `students_present` TEXT NULL,
  `students_absent` TEXT NULL,
  `problems` TEXT NULL,
  `attachment_link` TEXT NULL,
  `status` TEXT NULL,
  `created_at` TEXT NULL,
  `submitted_at` TEXT NULL,
  `reviewed_by` TEXT NULL,
  `reviewed_at` TEXT NULL,
  `review_note` TEXT NULL,
  PRIMARY KEY (`_seq`),
  KEY `ix_id` (`id`),
  KEY `ix_semester_id` (`semester_id`),
  KEY `ix_teacher_id` (`teacher_id`),
  KEY `ix_group_id` (`group_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `Internships` (
  `_seq` BIGINT NOT NULL AUTO_INCREMENT,
  `id` VARCHAR(64) NULL,
  `semester_id` VARCHAR(64) NULL,
  `student_code` TEXT NULL,
  `student_name` TEXT NULL,
  `group_id` VARCHAR(64) NULL,
  `company_name` TEXT NULL,
  `company_address` TEXT NULL,
  `company_phone` TEXT NULL,
  `mentor_name` TEXT NULL,
  `start_date` TEXT NULL,
  `end_date` TEXT NULL,
  `supervisor_teacher_id` VARCHAR(64) NULL,
  `status` TEXT NULL,
  `note` TEXT NULL,
  `created_by` TEXT NULL,
  `created_at` TEXT NULL,
  PRIMARY KEY (`_seq`),
  KEY `ix_id` (`id`),
  KEY `ix_semester_id` (`semester_id`),
  KEY `ix_group_id` (`group_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `SupervisionVisits` (
  `_seq` BIGINT NOT NULL AUTO_INCREMENT,
  `id` VARCHAR(64) NULL,
  `internship_id` VARCHAR(64) NULL,
  `visit_date` TEXT NULL,
  `visit_type` TEXT NULL,
  `meeting_link` TEXT NULL,
  `topic` TEXT NULL,
  `findings` TEXT NULL,
  `suggestions` TEXT NULL,
  `score` TEXT NULL,
  `evidence_link` TEXT NULL,
  `next_visit_date` TEXT NULL,
  `status` TEXT NULL,
  `created_by` TEXT NULL,
  `created_at` TEXT NULL,
  PRIMARY KEY (`_seq`),
  KEY `ix_id` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `_kv` (
  `k` VARCHAR(191) NOT NULL,
  `v` MEDIUMTEXT NULL,
  `exp` BIGINT NULL,
  PRIMARY KEY (`k`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
