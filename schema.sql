-- O-NET Student Analysis System
-- Modern Database Schema v1.1.0
-- Compatible with MySQL 5.7+ and SQLite

-- 1. Students Table
CREATE TABLE IF NOT EXISTS students (
  id INTEGER PRIMARY KEY AUTO_INCREMENT,
  student_id VARCHAR(50) UNIQUE NOT NULL,
  prefix VARCHAR(20),
  name VARCHAR(255) NOT NULL,
  grade_level VARCHAR(10) NOT NULL,
  room_number VARCHAR(10) NOT NULL
);

CREATE INDEX idx_students_grade_room ON students (grade_level, room_number);

-- 2. Indicators Table
CREATE TABLE IF NOT EXISTS indicators (
  id INTEGER PRIMARY KEY AUTO_INCREMENT,
  code VARCHAR(50) NOT NULL,
  description TEXT,
  subject VARCHAR(100),
  grade_level VARCHAR(10),
  exam_set VARCHAR(50) DEFAULT 'default',
  UNIQUE (code, subject, grade_level, exam_set)
);

CREATE INDEX idx_indicators_subject ON indicators (subject);
CREATE INDEX idx_indicators_exam_set ON indicators (exam_set);

-- 3. Questions Table
CREATE TABLE IF NOT EXISTS questions (
  id INTEGER PRIMARY KEY AUTO_INCREMENT,
  question_number INTEGER NOT NULL,
  max_score DOUBLE DEFAULT 1.0,  -- Using DOUBLE for high precision
  subject VARCHAR(100),
  exam_set VARCHAR(50) DEFAULT 'default',
  grade_level VARCHAR(10) 
);

CREATE INDEX idx_questions_lookup ON questions (question_number, exam_set);

-- 4. Question-Indicators Mapping (Many-to-Many)
CREATE TABLE IF NOT EXISTS question_indicators (
  id INTEGER PRIMARY KEY AUTO_INCREMENT,
  question_id INTEGER NOT NULL,
  indicator_id INTEGER NOT NULL,
  FOREIGN KEY (question_id) REFERENCES questions(id) ON DELETE CASCADE,
  FOREIGN KEY (indicator_id) REFERENCES indicators(id) ON DELETE CASCADE
);

CREATE INDEX idx_qi_question ON question_indicators (question_id);
CREATE INDEX idx_qi_indicator ON question_indicators (indicator_id);

-- 5. Scores Table
CREATE TABLE IF NOT EXISTS scores (
  id INTEGER PRIMARY KEY AUTO_INCREMENT,
  student_id VARCHAR(50) NOT NULL,
  question_number INTEGER NOT NULL,
  score_obtained DOUBLE DEFAULT 0.0, -- Using DOUBLE for high precision
  exam_set VARCHAR(50) DEFAULT 'default',
  FOREIGN KEY (student_id) REFERENCES students(student_id) ON DELETE CASCADE
);

CREATE UNIQUE INDEX idx_unique_score ON scores (student_id, question_number, exam_set);
CREATE INDEX idx_scores_analysis ON scores (question_number, exam_set);
