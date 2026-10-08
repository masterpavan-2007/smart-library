-- =====================================================================
-- Smart Library Management System (SLMS)
-- Database: smart_library
-- Import this file through phpMyAdmin (or `mysql -u root -p < smart_library.sql`)
-- =====================================================================

CREATE DATABASE IF NOT EXISTS smart_library CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE smart_library;

-- ---------------------------------------------------------------------
-- USERS (admin + librarian/staff login accounts)
-- Students also get a row here so everyone can log in from one table.
-- ---------------------------------------------------------------------
CREATE TABLE users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(120) NOT NULL,
    email VARCHAR(150) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    role ENUM('admin','librarian','student') NOT NULL,
    phone VARCHAR(20) DEFAULT NULL,
    photo VARCHAR(255) DEFAULT NULL,
    status ENUM('active','inactive') NOT NULL DEFAULT 'active',
    reset_token VARCHAR(255) DEFAULT NULL,
    reset_expires DATETIME DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- STUDENTS (extra profile fields, linked 1-1 to a users row)
-- ---------------------------------------------------------------------
CREATE TABLE students (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    roll_number VARCHAR(40) NOT NULL UNIQUE,
    course VARCHAR(100) DEFAULT NULL,
    department VARCHAR(100) DEFAULT NULL,
    year VARCHAR(20) DEFAULT NULL,
    division VARCHAR(20) DEFAULT NULL,
    address VARCHAR(255) DEFAULT NULL,
    registration_date DATE NOT NULL,
    max_books INT NOT NULL DEFAULT 3,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- STAFF (librarians, linked 1-1 to a users row)
-- ---------------------------------------------------------------------
CREATE TABLE staff (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    designation VARCHAR(100) DEFAULT 'Librarian',
    shift VARCHAR(50) DEFAULT NULL,
    joining_date DATE DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- CATEGORIES / AUTHORS / PUBLISHERS
-- ---------------------------------------------------------------------
CREATE TABLE categories (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL UNIQUE,
    description VARCHAR(255) DEFAULT NULL
) ENGINE=InnoDB;

CREATE TABLE authors (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(150) NOT NULL,
    bio TEXT DEFAULT NULL
) ENGINE=InnoDB;

CREATE TABLE publishers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(150) NOT NULL,
    address VARCHAR(255) DEFAULT NULL
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- BOOKS
-- ---------------------------------------------------------------------
CREATE TABLE books (
    id INT AUTO_INCREMENT PRIMARY KEY,
    isbn VARCHAR(30) NOT NULL UNIQUE,
    title VARCHAR(200) NOT NULL,
    author_id INT DEFAULT NULL,
    category_id INT DEFAULT NULL,
    publisher_id INT DEFAULT NULL,
    publication_year YEAR DEFAULT NULL,
    edition VARCHAR(30) DEFAULT NULL,
    language VARCHAR(40) DEFAULT 'English',
    pages INT DEFAULT NULL,
    price DECIMAL(10,2) DEFAULT 0.00,
    shelf_number VARCHAR(30) DEFAULT NULL,
    total_copies INT NOT NULL DEFAULT 1,
    available_copies INT NOT NULL DEFAULT 1,
    description TEXT DEFAULT NULL,
    image VARCHAR(255) DEFAULT NULL,
    status ENUM('active','inactive') NOT NULL DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (author_id) REFERENCES authors(id) ON DELETE SET NULL,
    FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE SET NULL,
    FOREIGN KEY (publisher_id) REFERENCES publishers(id) ON DELETE SET NULL,
    INDEX idx_title (title),
    INDEX idx_isbn (isbn)
) ENGINE=InnoDB;

-- Individual physical copies (barcode-level tracking, optional but useful)
CREATE TABLE book_copies (
    id INT AUTO_INCREMENT PRIMARY KEY,
    book_id INT NOT NULL,
    copy_code VARCHAR(40) NOT NULL UNIQUE,
    status ENUM('available','issued','lost','damaged') NOT NULL DEFAULT 'available',
    FOREIGN KEY (book_id) REFERENCES books(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- ISSUE / RETURN TRANSACTIONS
-- ---------------------------------------------------------------------
CREATE TABLE book_issues (
    id INT AUTO_INCREMENT PRIMARY KEY,
    student_id INT NOT NULL,
    book_id INT NOT NULL,
    issued_by INT DEFAULT NULL,          -- staff/admin user id
    issue_date DATE NOT NULL,
    due_date DATE NOT NULL,
    return_date DATE DEFAULT NULL,
    status ENUM('issued','returned','overdue','lost') NOT NULL DEFAULT 'issued',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE,
    FOREIGN KEY (book_id) REFERENCES books(id) ON DELETE CASCADE,
    FOREIGN KEY (issued_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE book_returns (
    id INT AUTO_INCREMENT PRIMARY KEY,
    issue_id INT NOT NULL,
    return_date DATE NOT NULL,
    late_days INT NOT NULL DEFAULT 0,
    fine_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    received_by INT DEFAULT NULL,
    remarks VARCHAR(255) DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (issue_id) REFERENCES book_issues(id) ON DELETE CASCADE,
    FOREIGN KEY (received_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- RESERVATIONS
-- ---------------------------------------------------------------------
CREATE TABLE reservations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    student_id INT NOT NULL,
    book_id INT NOT NULL,
    reservation_date DATE NOT NULL,
    expiry_date DATE DEFAULT NULL,
    status ENUM('pending','approved','ready','completed','cancelled','expired') NOT NULL DEFAULT 'pending',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE,
    FOREIGN KEY (book_id) REFERENCES books(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- FINES / PAYMENTS
-- ---------------------------------------------------------------------
CREATE TABLE fines (
    id INT AUTO_INCREMENT PRIMARY KEY,
    student_id INT NOT NULL,
    issue_id INT DEFAULT NULL,
    amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    reason VARCHAR(255) DEFAULT 'Late return',
    status ENUM('pending','paid','waived') NOT NULL DEFAULT 'pending',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE,
    FOREIGN KEY (issue_id) REFERENCES book_issues(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE payments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    fine_id INT NOT NULL,
    student_id INT NOT NULL,
    amount DECIMAL(10,2) NOT NULL,
    payment_method ENUM('cash','card','online') NOT NULL DEFAULT 'cash',
    received_by INT DEFAULT NULL,
    payment_date DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (fine_id) REFERENCES fines(id) ON DELETE CASCADE,
    FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE,
    FOREIGN KEY (received_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- NOTIFICATIONS
-- ---------------------------------------------------------------------
CREATE TABLE notifications (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    title VARCHAR(150) NOT NULL,
    message VARCHAR(255) NOT NULL,
    is_read TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- LIBRARY SETTINGS (single row of configurable values)
-- ---------------------------------------------------------------------
CREATE TABLE library_settings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    library_name VARCHAR(150) NOT NULL DEFAULT 'Smart Library',
    borrow_period_days INT NOT NULL DEFAULT 7,
    fine_per_day DECIMAL(10,2) NOT NULL DEFAULT 5.00,
    max_fine DECIMAL(10,2) NOT NULL DEFAULT 200.00,
    grace_period_days INT NOT NULL DEFAULT 0,
    max_books_per_student INT NOT NULL DEFAULT 3,
    reservation_valid_days INT NOT NULL DEFAULT 3
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- ACTIVITY LOGS
-- ---------------------------------------------------------------------
CREATE TABLE activity_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT DEFAULT NULL,
    action VARCHAR(255) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- VIRTUAL LIBRARY: DIGITAL BOOKS / E-BOOKS
-- ---------------------------------------------------------------------
CREATE TABLE digital_books (
    id INT AUTO_INCREMENT PRIMARY KEY,
    book_id INT NULL,
    title VARCHAR(200) NOT NULL,
    author_id INT NULL,
    author_name VARCHAR(150) NULL,
    category_id INT NULL,
    publisher_id INT NULL,
    publication_year YEAR NULL,
    edition VARCHAR(30) DEFAULT '1st',
    language VARCHAR(40) DEFAULT 'English',
    pages INT DEFAULT 10,
    description TEXT NULL,
    cover_image VARCHAR(255) NULL,
    file_path VARCHAR(255) NOT NULL,
    file_format VARCHAR(20) NOT NULL DEFAULT 'PDF',
    file_size_kb INT DEFAULT 0,
    access_type ENUM('free','restricted','open_access') NOT NULL DEFAULT 'free',
    license_info VARCHAR(255) DEFAULT 'Open Educational Resource / Demonstration',
    view_count INT NOT NULL DEFAULT 0,
    read_count INT NOT NULL DEFAULT 0,
    status ENUM('active','inactive') NOT NULL DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (book_id) REFERENCES books(id) ON DELETE SET NULL,
    FOREIGN KEY (author_id) REFERENCES authors(id) ON DELETE SET NULL,
    FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE SET NULL,
    FOREIGN KEY (publisher_id) REFERENCES publishers(id) ON DELETE SET NULL,
    INDEX idx_dtitle (title),
    INDEX idx_dcat (category_id),
    INDEX idx_dauthor (author_id),
    INDEX idx_dstatus (status)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- VIRTUAL LIBRARY: READING HISTORY & PROGRESS
-- ---------------------------------------------------------------------
CREATE TABLE reading_history (
    id INT AUTO_INCREMENT PRIMARY KEY,
    student_id INT NOT NULL,
    digital_book_id INT NOT NULL,
    first_accessed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    last_accessed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    last_page INT NOT NULL DEFAULT 1,
    total_pages INT NOT NULL DEFAULT 1,
    progress_percent DECIMAL(5,2) NOT NULL DEFAULT 0.00,
    reading_status ENUM('reading','completed') NOT NULL DEFAULT 'reading',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_stu_dbook (student_id, digital_book_id),
    FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE,
    FOREIGN KEY (digital_book_id) REFERENCES digital_books(id) ON DELETE CASCADE,
    INDEX idx_rh_stu (student_id),
    INDEX idx_rh_book (digital_book_id)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- VIRTUAL LIBRARY: BOOKMARKS
-- ---------------------------------------------------------------------
CREATE TABLE bookmarks (
    id INT AUTO_INCREMENT PRIMARY KEY,
    student_id INT NOT NULL,
    digital_book_id INT NOT NULL,
    page_number INT NOT NULL DEFAULT 1,
    note VARCHAR(255) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE,
    FOREIGN KEY (digital_book_id) REFERENCES digital_books(id) ON DELETE CASCADE,
    INDEX idx_bm_stubook (student_id, digital_book_id)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- FAVORITES (Physical & Digital Bookmarks)
-- ---------------------------------------------------------------------
CREATE TABLE favorites (
    id INT AUTO_INCREMENT PRIMARY KEY,
    student_id INT NOT NULL,
    book_id INT NULL,
    digital_book_id INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE,
    FOREIGN KEY (book_id) REFERENCES books(id) ON DELETE CASCADE,
    FOREIGN KEY (digital_book_id) REFERENCES digital_books(id) ON DELETE CASCADE,
    INDEX idx_fav_stu (student_id)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- LIBRARY ATTENDANCE
-- ---------------------------------------------------------------------
CREATE TABLE library_attendance (
    id INT AUTO_INCREMENT PRIMARY KEY,
    student_id INT NOT NULL,
    entry_time DATETIME NOT NULL,
    exit_time DATETIME NULL,
    purpose VARCHAR(100) DEFAULT 'Study / Reading',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE,
    INDEX idx_att_date (entry_time)
) ENGINE=InnoDB;

-- =====================================================================
-- SAMPLE DATA
-- =====================================================================

INSERT INTO library_settings (library_name, borrow_period_days, fine_per_day, max_fine, grace_period_days, max_books_per_student, reservation_valid_days)
VALUES ('Smart Library', 7, 5.00, 200.00, 0, 3, 3);

-- Password for ALL sample accounts below is:  Password@123
-- (bcrypt hash - verified to work with PHP's password_verify())
INSERT INTO users (name, email, password, role, phone, status) VALUES
('Library Admin', 'admin@library.com', '$2b$12$f.OqnZJ7vJgjHg83GQOXRuZ9z90i9/K/BEEnR1suvNa1mPLW2m3ge', 'admin', '9990000001', 'active'),
('Ravi Kulkarni', 'ravi.librarian@library.com', '$2b$12$f.OqnZJ7vJgjHg83GQOXRuZ9z90i9/K/BEEnR1suvNa1mPLW2m3ge', 'librarian', '9990000002', 'active'),
('Sneha Patil', 'sneha.librarian@library.com', '$2b$12$f.OqnZJ7vJgjHg83GQOXRuZ9z90i9/K/BEEnR1suvNa1mPLW2m3ge', 'librarian', '9990000003', 'active'),
('Aarav Sharma', 'aarav@student.com', '$2b$12$f.OqnZJ7vJgjHg83GQOXRuZ9z90i9/K/BEEnR1suvNa1mPLW2m3ge', 'student', '9990000011', 'active'),
('Diya Mehta', 'diya@student.com', '$2b$12$f.OqnZJ7vJgjHg83GQOXRuZ9z90i9/K/BEEnR1suvNa1mPLW2m3ge', 'student', '9990000012', 'active'),
('Kabir Singh', 'kabir@student.com', '$2b$12$f.OqnZJ7vJgjHg83GQOXRuZ9z90i9/K/BEEnR1suvNa1mPLW2m3ge', 'student', '9990000013', 'active'),
('Ananya Rao', 'ananya@student.com', '$2b$12$f.OqnZJ7vJgjHg83GQOXRuZ9z90i9/K/BEEnR1suvNa1mPLW2m3ge', 'student', '9990000014', 'active'),
('Vihaan Gupta', 'vihaan@student.com', '$2b$12$f.OqnZJ7vJgjHg83GQOXRuZ9z90i9/K/BEEnR1suvNa1mPLW2m3ge', 'student', '9990000015', 'active'),
('Ishita Nair', 'ishita@student.com', '$2b$12$f.OqnZJ7vJgjHg83GQOXRuZ9z90i9/K/BEEnR1suvNa1mPLW2m3ge', 'student', '9990000016', 'active'),
('Rohan Desai', 'rohan@student.com', '$2b$12$f.OqnZJ7vJgjHg83GQOXRuZ9z90i9/K/BEEnR1suvNa1mPLW2m3ge', 'student', '9990000017', 'active'),
('Meera Joshi', 'meera@student.com', '$2b$12$f.OqnZJ7vJgjHg83GQOXRuZ9z90i9/K/BEEnR1suvNa1mPLW2m3ge', 'student', '9990000018', 'active'),
('Aditya Verma', 'aditya@student.com', '$2b$12$f.OqnZJ7vJgjHg83GQOXRuZ9z90i9/K/BEEnR1suvNa1mPLW2m3ge', 'student', '9990000019', 'active'),
('Priya Iyer', 'priya@student.com', '$2b$12$f.OqnZJ7vJgjHg83GQOXRuZ9z90i9/K/BEEnR1suvNa1mPLW2m3ge', 'student', '9990000020', 'active'),
('Pavan', 'pavan61@gmail.com', '$2y$10$TdMzmaJB48mbaFmeVe5UAegVvRdxUd//W0s9kBwBl0q70cU/wrBei', 'student', '9876543201', 'active'),
('Anushka', 'anushka65@gmail.com', '$2y$10$Pr86KsE9aG3WvYVbOaFLMOLgKzhQQJb0oIPzE/qwxNzAuOFXEecz.', 'student', '9876543202', 'active'),
('Swaroop', 'swaroop46@gmail.com', '$2y$10$Itt9IjC.hZLwTmqum5r45O1jda/VAYo0RaUAxe64.H.IpOEJ5tyte', 'student', '9876543203', 'active'),
('Jay', 'jay23@gmail.com', '$2y$10$Qqt3WnGifuWMpJwvxzj2euT7bYd397JG7cj59b1CHo6wXOQX1d7zK', 'librarian', '9876543204', 'active'),
('Admin 1', 'admin1@gmail.com', '$2y$10$SokG3tQqyEqUB5nsNCPh6ev5B7ya9i21Nw/Z.LSWbieyTwhl.IAuO', 'admin', '9876543205', 'active');

INSERT INTO staff (user_id, designation, shift, joining_date) VALUES
(2, 'Senior Librarian', 'Morning', '2023-06-01'),
(3, 'Librarian', 'Evening', '2024-01-15'),
(17, 'Librarian', 'Morning', '2025-01-10');

INSERT INTO students (user_id, roll_number, course, department, year, division, address, registration_date, max_books) VALUES
(4,  'CS2023-01', 'B.Tech', 'Computer Science', '2nd Year', 'A', 'Pune', '2024-07-01', 3),
(5,  'CS2023-02', 'B.Tech', 'Computer Science', '2nd Year', 'A', 'Pune', '2024-07-01', 3),
(6,  'IT2023-05', 'B.Tech', 'Information Technology', '3rd Year', 'B', 'Mumbai', '2023-07-05', 3),
(7,  'EC2023-09', 'B.Tech', 'Electronics', '1st Year', 'A', 'Pune', '2025-07-10', 3),
(8,  'CS2022-14', 'B.Tech', 'Computer Science', '3rd Year', 'C', 'Nashik', '2023-07-08', 3),
(9,  'ME2023-03', 'B.Tech', 'Mechanical', '2nd Year', 'A', 'Pune', '2024-07-02', 3),
(10, 'CS2024-22', 'B.Tech', 'Computer Science', '1st Year', 'B', 'Pune', '2025-07-15', 3),
(11, 'IT2022-11', 'B.Tech', 'Information Technology', '3rd Year', 'A', 'Thane', '2023-07-01', 3),
(12, 'EC2024-07', 'B.Tech', 'Electronics', '1st Year', 'B', 'Pune', '2025-07-15', 3),
(13, 'CS2023-19', 'B.Tech', 'Computer Science', '2nd Year', 'C', 'Pune', '2024-07-03', 3),
(14, 'CS2024-61', 'B.Tech', 'Computer Science', '1st Year', 'A', 'Campus', '2025-07-15', 3),
(15, 'CS2024-65', 'B.Tech', 'Computer Science', '1st Year', 'B', 'Campus', '2025-07-15', 3),
(16, 'CS2024-46', 'B.Tech', 'Computer Science', '1st Year', 'A', 'Campus', '2025-07-15', 3);

INSERT INTO categories (name, description) VALUES
('Computer Science', 'Programming, algorithms and CS theory'),
('Fiction', 'Novels and short stories'),
('Mathematics', 'Pure and applied mathematics'),
('Electronics', 'Circuits and embedded systems'),
('Self-Help', 'Personal growth and productivity'),
('History', 'World and regional history'),
('Biography', 'Life stories of notable people'),
('Science', 'General and popular science'),
('Education', 'Curriculum, pedagogy, academic skills and study guides'),
('Comics', 'Graphic novels, western comics and illustrated stories'),
('Manga', 'Japanese manga, graphic arts, and visual storytelling'),
('Horror', 'Supernatural suspense, thrillers, and psychological horror'),
('Fantasy', 'High fantasy, magical worlds, myth and heroic epics'),
('Science Fiction', 'Futuristic technology, space exploration, and sci-fi'),
('Mystery', 'Detective fiction, crime, investigative cases and puzzles'),
('Thriller', 'Fast-paced suspense, political and psychological thrillers'),
('Romance', 'Contemporary and historical romantic fiction'),
('Technology', 'Emerging technologies, cloud, security, and AI'),
('General Knowledge', 'Encyclopedias, quizzes, trivia and world facts');

INSERT INTO authors (name) VALUES
('Robert C. Martin'),('Thomas H. Cormen'),('George Orwell'),('J.K. Rowling'),
('James Clear'),('Yuval Noah Harari'),('Walter Isaacson'),('Stephen Hawking'),
('Agatha Christie'),('Paulo Coelho'),('Chinua Achebe'),('Andrew S. Tanenbaum'),
('Dan Brown'),('Malcolm Gladwell'),('Khaled Hosseini');

INSERT INTO publishers (name) VALUES
('Prentice Hall'),('MIT Press'),('Penguin Books'),('Bloomsbury'),
('Random House'),('Harper Collins'),('Simon & Schuster'),('O''Reilly Media');

INSERT INTO books (isbn, title, author_id, category_id, publisher_id, publication_year, edition, language, pages, price, shelf_number, total_copies, available_copies, description) VALUES
('9780132350884','Clean Code',1,1,1,2008,'1st','English',464,899.00,'CS-01',4,4,'A handbook of agile software craftsmanship.'),
('9780262033848','Introduction to Algorithms',2,1,2,2009,'3rd','English',1312,1499.00,'CS-02',3,3,'The classic algorithms textbook (CLRS).'),
('9780451524935','1984',3,2,3,1949,'1st','English',328,299.00,'FI-01',5,5,'Dystopian classic about surveillance and control.'),
('9780747532699','Harry Potter and the Philosopher''s Stone',4,2,4,1997,'1st','English',223,399.00,'FI-02',6,6,'The first book in the Harry Potter series.'),
('9780735211292','Atomic Habits',5,5,5,2018,'1st','English',320,499.00,'SH-01',5,5,'Tiny changes, remarkable results.'),
('9780062316097','Sapiens: A Brief History of Humankind',6,6,6,2011,'1st','English',443,599.00,'HI-01',4,4,'A brief history of humankind.'),
('9781451648539','Steve Jobs',7,7,7,2011,'1st','English',656,699.00,'BI-01',3,3,'Biography of Apple co-founder Steve Jobs.'),
('9780553380163','A Brief History of Time',8,8,3,1988,'1st','English',256,349.00,'SC-01',3,3,'An exploration of cosmology for general readers.'),
('9780062073488','Murder on the Orient Express',9,2,6,1934,'1st','English',256,299.00,'FI-03',4,4,'A classic Hercule Poirot mystery.'),
('9780062315007','The Alchemist',10,2,6,1988,'1st','English',208,349.00,'FI-04',5,5,'A shepherd boy''s journey of self-discovery.'),
('9780435905255','Things Fall Apart',11,2,4,1958,'1st','English',209,299.00,'FI-05',3,3,'A story of pre-colonial life in Nigeria.'),
('9780132126953','Modern Operating Systems',12,1,1,2014,'4th','English',1136,1299.00,'CS-03',3,3,'A comprehensive OS textbook.'),
('9780307474278','The Da Vinci Code',13,2,5,2003,'1st','English',454,399.00,'FI-06',4,4,'A mystery thriller involving art and religion.'),
('9780316010665','Outliers',14,5,7,2008,'1st','English',309,449.00,'SH-02',3,3,'The story of success explained.'),
('9781594631931','The Kite Runner',15,2,5,2003,'1st','English',371,399.00,'FI-07',4,4,'A story of friendship and redemption in Afghanistan.'),
('9780201633610','Design Patterns',1,1,1,1994,'1st','English',395,999.00,'CS-04',3,3,'Elements of reusable object-oriented software.'),
('9780132126352','Effective Java',1,1,1,2017,'3rd','English',412,899.00,'CS-05',3,3,'Best practices for the Java platform.'),
('9780143127550','Thinking, Fast and Slow',6,5,3,2011,'1st','English',499,499.00,'SH-03',3,3,'How we make decisions.'),
('9780671027032','The Diary of a Young Girl',9,7,7,1947,'1st','English',283,249.00,'BI-02',3,3,'The diary of Anne Frank.'),
('9780061122415','The Alchemist (Special Ed.)',10,2,6,2014,'Special','English',197,399.00,'FI-08',3,3,'Special edition of The Alchemist.'),
('9780393609515','Astrophysics for People in a Hurry',8,8,7,2017,'1st','English',222,349.00,'SC-02',3,3,'A quick tour of the universe.'),
('9780262046305','Algorithms Unlocked',2,1,2,2013,'1st','English',240,599.00,'CS-06',3,3,'A friendly introduction to algorithms.'),
('9780345391803','The Hitchhiker''s Guide to the Galaxy',3,2,3,1979,'1st','English',224,299.00,'FI-09',4,4,'A comedic science fiction classic.'),
('9780679783268','Pride and Prejudice',3,2,3,1813,'1st','English',432,249.00,'FI-10',3,3,'A classic romantic novel of manners.'),
('9780199535569','Frankenstein',3,2,3,1818,'1st','English',280,229.00,'FI-11',3,3,'A gothic science fiction novel.'),
('9780393350338','Silence',6,6,7,1966,'1st','English',201,349.00,'HI-02',2,2,'A historical novel of faith and persecution.'),
('9780857197689','The Psychology of Money',5,5,4,2020,'1st','English',256,399.00,'SH-04',4,4,'Timeless lessons on wealth and greed.'),
('9780134685991','Effective Modern C++',1,1,1,2014,'1st','English',334,899.00,'CS-07',2,2,'Best practices for modern C++.'),
('9780262510875','Structure and Interpretation of Computer Programs',2,1,2,1996,'2nd','English',657,999.00,'CS-08',2,2,'A foundational CS text using Scheme.'),
('9780812981605','The Immortal Life of Henrietta Lacks',6,8,5,2010,'1st','English',381,399.00,'SC-03',2,2,'The story behind the HeLa cell line.');

-- Sample issue transactions (some active, some overdue, some returned)
INSERT INTO book_issues (student_id, book_id, issued_by, issue_date, due_date, return_date, status) VALUES
(1, 1, 2, '2026-09-01', '2026-09-08', NULL, 'issued'),
(2, 4, 2, '2026-08-25', '2026-09-01', NULL, 'overdue'),
(3, 5, 3, '2026-09-05', '2026-09-12', NULL, 'issued'),
(4, 6, 3, '2026-08-20', '2026-08-27', '2026-08-29', 'returned'),
(5, 9, 2, '2026-09-02', '2026-09-09', NULL, 'issued'),
(6, 10, 2, '2026-08-15', '2026-08-22', '2026-08-22', 'returned'),
(7, 3, 3, '2026-08-30', '2026-09-06', NULL, 'issued'),
(8, 12, 3, '2026-08-10', '2026-08-17', '2026-08-20', 'returned');

UPDATE books SET available_copies = available_copies - 1 WHERE id IN (1,4,5,9,3,12) AND id NOT IN (
    SELECT book_id FROM (SELECT book_id FROM book_issues WHERE status='returned') AS t
);

-- Sample completed return with fine
INSERT INTO book_returns (issue_id, return_date, late_days, fine_amount, received_by, remarks) VALUES
(4, '2026-08-29', 2, 10.00, 3, 'Returned in good condition'),
(6, '2026-08-22', 0, 0.00, 2, 'On time'),
(8, '2026-08-20', 3, 15.00, 3, 'Slightly delayed');

-- Sample fines (one pending on the overdue book, plus the two paid ones above)
INSERT INTO fines (student_id, issue_id, amount, reason, status) VALUES
(2, 2, 50.00, 'Overdue book - still pending', 'pending'),
(4, 4, 10.00, 'Late return', 'paid'),
(8, 8, 15.00, 'Late return', 'paid');

INSERT INTO payments (fine_id, student_id, amount, payment_method, received_by, payment_date) VALUES
(2, 4, 10.00, 'cash', 3, '2026-08-29 11:00:00'),
(3, 8, 15.00, 'cash', 3, '2026-08-20 15:30:00');

-- Sample reservations
INSERT INTO reservations (student_id, book_id, reservation_date, expiry_date, status) VALUES
(9, 27, '2026-09-08', '2026-09-11', 'pending'),
(10, 29, '2026-09-09', '2026-09-12', 'pending'),
(1, 30, '2026-09-07', '2026-09-10', 'ready');

-- Sample notifications
INSERT INTO notifications (user_id, title, message, is_read) VALUES
(4, 'Book Issued', 'Clean Code has been issued to you. Due on 2026-09-08.', 0),
(5, 'Due Soon', 'A Brief History of Time is due in 3 days.', 0),
(6, 'Book Available', 'Your reserved book is ready for pickup.', 0);

INSERT INTO activity_logs (user_id, action) VALUES
(1, 'System initialized with sample data'),
(2, 'Issued "Clean Code" to Aarav Sharma'),
(3, 'Processed return for "Atomic Habits"');

-- Sample Digital Books (15 E-Books)
INSERT INTO digital_books (book_id, title, author_id, author_name, category_id, publisher_id, publication_year, edition, language, pages, description, file_path, file_format, file_size_kb, access_type, license_info, view_count, read_count, status) VALUES
(1, 'Clean Code: Digital Handbook', 1, 'Robert C. Martin', 1, 1, 2021, 'Digital Edition', 'English', 6, 'The digital reference guide for writing robust, readable, and maintainable software code.', 'clean_code_digital.pdf', 'PDF', 14, 'free', 'Open Educational Resource', 142, 89, 'active'),
(2, 'Introduction to Algorithms (Interactive Edition)', 2, 'Thomas H. Cormen', 1, 2, 2022, '4th Digital', 'English', 5, 'Comprehensive digital algorithms primer covering sorting, graph algorithms, and dynamic programming.', 'algorithms_interactive.pdf', 'PDF', 12, 'free', 'Academic Open Access', 210, 135, 'active'),
(NULL, 'Foundations of Artificial Intelligence & Machine Learning', 12, 'Andrew S. Tanenbaum', 18, 2, 2024, '1st', 'English', 5, 'A structured introduction to supervised, unsupervised learning, neural networks, and LLMs.', 'ai_ml_foundations.pdf', 'PDF', 13, 'free', 'Creative Commons BY-NC 4.0', 320, 180, 'active'),
(NULL, 'Principles of Modern Educational Psychology', 14, 'Malcolm Gladwell', 9, 3, 2023, '2nd', 'English', 4, 'Modern insights into student cognition, memory retention, motivation, and classroom dynamics.', 'educational_psychology.pdf', 'PDF', 10, 'free', 'Open Educational Resource', 95, 54, 'active'),
(NULL, 'Chronicles of the Solar Nebula', 8, 'Stephen Hawking', 14, 6, 2025, '1st', 'English', 5, 'A hard science fiction odyssey exploring a human colony around the rings of Saturn.', 'solar_nebula_chronicles.pdf', 'PDF', 12, 'free', 'Public Domain Demo', 178, 112, 'active'),
(NULL, 'The Shadow Over Blackwood Manor', 9, 'Agatha Christie', 12, 6, 2022, '1st', 'English', 4, 'A chilling tale of an ancestral estate plagued by unexplained whispers behind locked library doors.', 'shadow_blackwood_manor.pdf', 'PDF', 11, 'free', 'Educational Fair Use', 230, 165, 'active'),
(NULL, 'The Whispering Cipher of Alexandria', 13, 'Dan Brown', 15, 5, 2023, '1st', 'English', 5, 'A cryptographer and historian race against time to decode a lost papyrus scroll in Alexandria.', 'whispering_cipher_alexandria.pdf', 'PDF', 12, 'free', 'Open Educational Resource', 190, 120, 'active'),
(NULL, 'The Clockwork Citadel: Realm of Aethelgard', 4, 'J.K. Rowling', 13, 4, 2024, 'Illustrated Digital', 'English', 5, 'In a world powered by arcane clockwork gears and dragon fire, an apprentice artificer uncovers treason.', 'clockwork_citadel.pdf', 'PDF', 12, 'free', 'Open Educational Resource', 280, 195, 'active'),
(NULL, 'Cybersecurity Engineering & Threat Defense', 1, 'Robert C. Martin', 18, 8, 2024, '3rd', 'English', 5, 'A practical textbook covering zero-trust security, cryptography, network defense, and SOC operations.', 'cybersecurity_threat_defense.pdf', 'PDF', 14, 'free', 'Open Educational Resource', 310, 215, 'active'),
(5, 'Atomic Habits: The Daily Micro-Action Workbook', 5, 'James Clear', 5, 5, 2023, 'Special Digital', 'English', 5, 'Practical daily routines, habit stacking templates, and tracking charts for college students.', 'atomic_habits_workbook.pdf', 'PDF', 12, 'free', 'Author Companion Guide', 450, 340, 'active'),
(NULL, 'The Way of the Blade: Samurai Legends', 10, 'Paulo Coelho', 11, 3, 2024, 'Volume 1', 'English', 4, 'A graphic novel exploring loyalty, honor, and inner peace in the Sengoku era of ancient Japan.', 'samurai_legends_manga.pdf', 'PDF', 11, 'free', 'Open Illustrated Demo', 380, 290, 'active'),
(NULL, 'Calculus & Linear Algebra for Computer Scientists', 2, 'Thomas H. Cormen', 3, 2, 2023, '2nd', 'English', 5, 'Vectors, matrices, eigenvalues, gradients, and multivariable calculus tailored for 3D graphics and ML.', 'calculus_linear_algebra_cs.pdf', 'PDF', 13, 'free', 'Open Courseware Text', 160, 98, 'active'),
(NULL, 'Dawn of the Republic: A Diplomatic History', 6, 'Yuval Noah Harari', 6, 6, 2022, '1st', 'English', 4, 'A study of treaties, diplomatic envoys, and constitutional formation during revolutionary eras.', 'dawn_of_republic_history.pdf', 'PDF', 11, 'free', 'Open Access Historical Series', 135, 88, 'active'),
(7, 'Steve Jobs: The Digital Innovation Blueprint', 7, 'Walter Isaacson', 7, 7, 2023, 'Leadership Edition', 'English', 5, 'Lessons in product design, minimalism, corporate vision, and transformative tech entrepreneurship.', 'steve_jobs_innovation.pdf', 'PDF', 12, 'free', 'Educational Case Study', 290, 195, 'active'),
(NULL, 'The Cosmic Canvas: Visual Astronomy', 8, 'Stephen Hawking', 19, 3, 2024, '1st', 'English', 4, 'A visually rich guide to galaxies, nebulae, black holes, and the cosmic microwave background.', 'cosmic_canvas_astronomy.pdf', 'PDF', 11, 'free', 'Open Educational Resource', 175, 110, 'active');

-- Sample Reading History
INSERT INTO reading_history (student_id, digital_book_id, first_accessed_at, last_accessed_at, last_page, total_pages, progress_percent, reading_status) VALUES
(1, 1, '2026-09-20 10:00:00', '2026-09-29 16:30:00', 3, 6, 50.00, 'reading'),
(1, 2, '2026-09-15 14:00:00', '2026-09-28 11:20:00', 5, 5, 100.00, 'completed'),
(2, 3, '2026-09-22 09:15:00', '2026-09-29 18:45:00', 2, 5, 40.00, 'reading');

-- Sample Bookmarks
INSERT INTO bookmarks (student_id, digital_book_id, page_number, note) VALUES
(1, 1, 2, 'Great explanation of functions and naming'),
(1, 1, 4, 'Unit testing rules - review before exam'),
(2, 3, 3, 'Neural network backprop formulas');

-- Sample Favorites
INSERT INTO favorites (student_id, book_id, digital_book_id) VALUES
(1, NULL, 1),
(1, NULL, 3),
(1, 1, NULL);

-- Sample Attendance
INSERT INTO library_attendance (student_id, entry_time, exit_time, purpose) VALUES
(1, '2026-09-29 09:30:00', '2026-09-29 11:45:00', 'Virtual & Physical Reading'),
(2, '2026-09-29 10:15:00', '2026-09-29 12:00:00', 'Research & Study');

