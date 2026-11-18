-- futa_homes.sql: create the application database if needed, then select it.
-- This does not drop existing databases or tables.
CREATE DATABASE IF NOT EXISTS futa_homes
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE futa_homes;

-- All account types share one login table. Agents have an agent_profiles row;
-- admins are users with role='admin' and do not have an agent profile.
CREATE TABLE IF NOT EXISTS users (
  id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(120) NOT NULL,
  email VARCHAR(190) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  role VARCHAR(10) NOT NULL DEFAULT 'user' CHECK (role IN ('user','agent','admin')),
  phone VARCHAR(30),
  avatar VARCHAR(255),
  recovery_pin_hash VARCHAR(255),
  pin_failures INT NOT NULL DEFAULT 0,
  pin_locked_until DATETIME,
  is_suspended BOOLEAN NOT NULL DEFAULT FALSE,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS agent_profiles (
  user_id INT NOT NULL PRIMARY KEY,
  business_name VARCHAR(160),
  bio TEXT,
  verification_status VARCHAR(20) NOT NULL DEFAULT 'not_submitted'
    CHECK (verification_status IN ('not_submitted','pending','approved','rejected')),
  verification_document VARCHAR(255),
  verification_note TEXT,
  verified_until DATETIME,
  plan VARCHAR(10) NOT NULL DEFAULT 'free' CHECK (plan IN ('free','pro','premium')),
  plan_expires_at DATETIME,
  CONSTRAINT fk_agent_profiles_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS properties (
  id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  agent_id INT NOT NULL,
  title VARCHAR(170) NOT NULL,
  description TEXT NOT NULL,
  address VARCHAR(200) NOT NULL,
  location VARCHAR(120) NOT NULL,
  property_type VARCHAR(20) NOT NULL DEFAULT 'selfcon'
    CHECK (property_type IN ('selfcon','apartment','hostel','shared','house','other')),
  listing_type VARCHAR(10) NOT NULL DEFAULT 'rent' CHECK (listing_type IN ('rent','sale')),
  price DECIMAL(13,2) NOT NULL CHECK (price > 0),
  price_period VARCHAR(10) NOT NULL DEFAULT 'year' CHECK (price_period IN ('year','month','once')),
  total_package DECIMAL(13,2),
  bedrooms SMALLINT NOT NULL DEFAULT 1 CHECK (bedrooms >= 0),
  bathrooms SMALLINT NOT NULL DEFAULT 1 CHECK (bathrooms >= 0),
  availability VARCHAR(12) NOT NULL DEFAULT 'available' CHECK (availability IN ('available','unavailable')),
  moderation_status VARCHAR(10) NOT NULL DEFAULT 'pending' CHECK (moderation_status IN ('pending','approved','rejected')),
  moderation_note TEXT,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY ix_visibility (moderation_status, availability, created_at),
  KEY ix_agent (agent_id),
  CONSTRAINT fk_properties_agent FOREIGN KEY (agent_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS property_media (
  id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  property_id INT NOT NULL,
  file_path VARCHAR(255) NOT NULL,
  media_type VARCHAR(5) NOT NULL CHECK (media_type IN ('image','video')),
  sort_order INT NOT NULL DEFAULT 0,
  KEY ix_property_media_property (property_id),
  CONSTRAINT fk_property_media_property FOREIGN KEY (property_id) REFERENCES properties(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS favorites (
  user_id INT NOT NULL,
  property_id INT NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (user_id, property_id),
  CONSTRAINT fk_favorites_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_favorites_property FOREIGN KEY (property_id) REFERENCES properties(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS bookings (
  id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  property_id INT NOT NULL,
  user_id INT NOT NULL,
  agent_id INT NOT NULL,
  viewing_at DATETIME NOT NULL,
  phone VARCHAR(30) NOT NULL,
  message TEXT,
  status VARCHAR(10) NOT NULL DEFAULT 'pending' CHECK (status IN ('pending','confirmed','completed','cancelled')),
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_bookings_agent (agent_id, status),
  CONSTRAINT fk_bookings_property FOREIGN KEY (property_id) REFERENCES properties(id) ON DELETE CASCADE,
  CONSTRAINT fk_bookings_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_bookings_agent FOREIGN KEY (agent_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS enquiries (
  id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  reference VARCHAR(35) NOT NULL UNIQUE,
  property_id INT NOT NULL,
  user_id INT NOT NULL,
  agent_id INT NOT NULL,
  message TEXT,
  status VARCHAR(10) NOT NULL DEFAULT 'open' CHECK (status IN ('open','contacted','closed')),
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_enquiries_property FOREIGN KEY (property_id) REFERENCES properties(id) ON DELETE CASCADE,
  CONSTRAINT fk_enquiries_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_enquiries_agent FOREIGN KEY (agent_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS agent_reviews (
  id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  agent_id INT NOT NULL,
  user_id INT NOT NULL,
  rating SMALLINT NOT NULL CHECK (rating BETWEEN 1 AND 5),
  comment TEXT NOT NULL,
  status VARCHAR(10) NOT NULL DEFAULT 'pending' CHECK (status IN ('pending','approved','rejected')),
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT uq_review UNIQUE (agent_id, user_id),
  CONSTRAINT fk_agent_reviews_agent FOREIGN KEY (agent_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_agent_reviews_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS property_views (
  id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  property_id INT NOT NULL,
  user_id INT,
  viewed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_views (property_id, viewed_at),
  CONSTRAINT fk_property_views_property FOREIGN KEY (property_id) REFERENCES properties(id) ON DELETE CASCADE,
  CONSTRAINT fk_property_views_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS payments (
  id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  agent_id INT NOT NULL,
  reference VARCHAR(100) NOT NULL UNIQUE,
  plan VARCHAR(10) NOT NULL CHECK (plan IN ('pro','premium')),
  amount_kobo INT NOT NULL CHECK (amount_kobo >= 0),
  provider VARCHAR(10) NOT NULL DEFAULT 'paystack' CHECK (provider IN ('paystack','admin')),
  status VARCHAR(10) NOT NULL DEFAULT 'pending' CHECK (status IN ('pending','paid','failed')),
  paid_at DATETIME,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_payments_agent FOREIGN KEY (agent_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS support_tickets (
  id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  subject VARCHAR(180) NOT NULL,
  message TEXT NOT NULL,
  admin_reply TEXT,
  status VARCHAR(10) NOT NULL DEFAULT 'open' CHECK (status IN ('open','answered','closed')),
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_support_tickets_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS notifications (
  id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  content VARCHAR(255) NOT NULL,
  is_read BOOLEAN NOT NULL DEFAULT FALSE,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_notice (user_id, is_read),
  CONSTRAINT fk_notifications_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS conversations (
  id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  property_id INT,
  user_id INT NOT NULL,
  agent_id INT NOT NULL,
  last_message TEXT,
  last_message_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_conv (property_id, user_id, agent_id),
  KEY ix_user_conv (user_id, last_message_at),
  KEY ix_agent_conv (agent_id, last_message_at),
  CONSTRAINT fk_conv_property FOREIGN KEY (property_id) REFERENCES properties(id) ON DELETE SET NULL,
  CONSTRAINT fk_conv_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_conv_agent FOREIGN KEY (agent_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS direct_messages (
  id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  conversation_id INT NOT NULL,
  sender_id INT NOT NULL,
  message TEXT NOT NULL,
  is_read BOOLEAN NOT NULL DEFAULT FALSE,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_dm_conv (conversation_id, created_at),
  CONSTRAINT fk_dm_conv FOREIGN KEY (conversation_id) REFERENCES conversations(id) ON DELETE CASCADE,
  CONSTRAINT fk_dm_sender FOREIGN KEY (sender_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS property_questions (
  id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  property_id INT NOT NULL,
  user_id INT NOT NULL,
  question TEXT NOT NULL,
  answer TEXT,
  answered_by INT,
  answered_at DATETIME,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_prop_questions (property_id, created_at),
  CONSTRAINT fk_prop_questions_property FOREIGN KEY (property_id) REFERENCES properties(id) ON DELETE CASCADE,
  CONSTRAINT fk_prop_questions_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_prop_questions_answered_by FOREIGN KEY (answered_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS property_reviews (
  id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  property_id INT NOT NULL,
  user_id INT NOT NULL,
  rating SMALLINT NOT NULL CHECK (rating BETWEEN 1 AND 5),
  comment TEXT NOT NULL,
  category_water VARCHAR(30) DEFAULT NULL,
  category_power VARCHAR(30) DEFAULT NULL,
  category_security VARCHAR(30) DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_property_user_review (property_id, user_id),
  KEY ix_prop_reviews (property_id, created_at),
  CONSTRAINT fk_prop_reviews_property FOREIGN KEY (property_id) REFERENCES properties(id) ON DELETE CASCADE,
  CONSTRAINT fk_prop_reviews_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
