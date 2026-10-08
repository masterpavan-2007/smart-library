# Smart Library Management System (SLMS) with Virtual Library

A complete, secure, full-stack college mini-project integrating **Physical Library Circulation** and an **Interactive Virtual Library (E-Book Reader & Digital Archives)** — built with plain PHP 8+, MySQL, and vanilla HTML5/CSS3/JavaScript (no bulky frameworks), specifically designed for XAMPP.

---

## 1. Project Introduction

The **Smart Library Management System (SLMS) with Virtual Library** combines traditional campus physical library inventory management with a modern digital e-book reading environment. Students can check physical shelf availability, reserve physical copies, and simultaneously read digital textbooks, academic research, manga, and open-access literature online within a built-in browser reader with automatic progress tracking and page bookmarking.

---

## 2. Main Objectives & Architecture

- **Unified Catalogue**: Browse both physical books and digital e-books side-by-side with clear distinctions.
- **Physical Library Management**: Complete book issue, return, late-fee calculation, reservations, student profiles, and circulation history.
- **Virtual Library Management**: Upload, categorize, manage, and read digital PDFs with in-browser zooming, page stepping, bookmarking, and reading progress auto-saving.
- **Role-Based Access Control**: Separate, secure dashboards for Library Admin, Reception/Librarians, and Students.
- **Zero Third-Party PHP Frameworks**: Pure PHP 8+ PDO with bcrypt password hashing, prepared statements, and CSRF protection.

---

## 3. Key Features by Module

### A. Physical Library Module
- **Inventory & Shelf Management**: Track physical books by ISBN, Shelf Number, Edition, Total Copies, and Real-Time Available Copies.
- **Issue & Return Circulation**: Automatic issue limit validation (default 3 books), due-date calculation, and transaction logging.
- **Automated Fine Calculation**: Dynamic calculation based on late days, configurable daily rates, grace periods, and maximum caps.
- **Reservations**: Students can reserve out-of-stock physical books; automated status lifecycle (`pending`, `ready`, `completed`, `cancelled`).
- **Payments & Receipts**: Record cash/card/online fine payments with payment logs.

### B. Virtual Library Module
- **Digital Book Catalogue**: 15 pre-loaded sample e-books across 17 categories (Computer Science, Technology, Education, Science Fiction, Horror, Manga, Fantasy, History, Mathematics, etc.).
- **Interactive In-Browser PDF Reader & Text-to-Speech (Read Aloud)**:
  - **Text-to-Speech (Read Aloud)**: In-browser voice synthesis powered by HTML5 SpeechSynthesis API.
    - Play, Pause, Resume, Stop, and sentence stepping (Previous/Next Sentence).
    - Speech speed selector (0.75x, 1x, 1.25x, 1.5x, 2x).
    - Voice selection with language filtering and system fallback.
    - Dual mode: Read Current Page/Chapter vs. Read Highlighted/Selected Text (with floating selection tooltip).
    - Live sentence-by-sentence teleprompter with glowing active sentence highlight and auto-scroll.
    - Auto-play Next Chapter toggle with automatic seamless page transition.
    - Persistent listening position (remembers last page, sentence index, rate, and voice preference).
    - Robust edge-case guards (15s Chrome keepalive heartbeat, duplicate speech instance cancellation).
  - Full-screen mode, Zoom In / Zoom Out / Reset / Fit to Width.
  - Page stepper and keyboard navigation (Left/Right arrows, +/- keys).
  - Resume Reading banner: automatically resumes from the student's last read page.
  - Page-level Bookmarking with custom notes.
  - Add/Remove from personal Favorites.
  - Book synopsis & licensing drawer.
- **Reading Progress Tracking**: Automatically calculates progress percentage and marks books as "Reading" or "Completed".
- **Secure PDF Delivery**: Digital files are streamed via authenticated PHP controller (`student/serve-pdf.php`) preventing unauthorized direct file exposure and directory traversal attacks.

---

## 4. User Roles & Capabilities

| Feature / Permission | Admin | Librarian | Student |
| :--- | :---: | :---: | :---: |
| Overview Dashboard with Live Charts | ✅ | ✅ | ✅ |
| Manage Physical Books (CRUD) | ✅ | View / Search | Search & Reserve |
| Upload & Manage Digital Books (PDFs) | ✅ | View & Usage | Read Online & Bookmark |
| Issue & Return Physical Books | ✅ | ✅ | ❌ |
| Record Fine Payments | ✅ | ✅ | View & Payment History |
| Manage Students & Staff | ✅ | Student Search | ❌ |
| Manage Categories, Authors, Publishers | ✅ | ❌ | ❌ |
| System Reports (Physical & Virtual + CSV) | ✅ | Daily Reports | ❌ |
| Continue Reading & Bookmarks | ❌ | ❌ | ✅ |
| Favorites (Physical & Digital) | ❌ | ❌ | ✅ |

---

## 5. Technology Stack

- **Frontend**: HTML5, CSS3 (Modern Glassmorphism & Custom Design System), Vanilla JavaScript (ES6+).
- **Backend**: PHP 8+ (Plain PHP, PDO Database Layer, Session Security).
- **Database**: MySQL / MariaDB (InnoDB, Foreign Key Constraints, Cascades, Indexes).
- **Digital Reader**: PDF.js (CDN) with native browser object/canvas fallback.
- **Icons & Visuals**: Font Awesome 6 (CDN).
- **Data Visualization**: Chart.js 4 (Issues vs Returns, Category Doughnut, Views Bar Chart).
- **Server Environment**: Apache via XAMPP.

---

## 6. System Requirements

- **Operating System**: Windows, macOS, or Linux.
- **Web Stack**: XAMPP (Apache 2.4+, MySQL 5.7+ / MariaDB 10.4+, PHP 8.0+).
- **Browser**: Google Chrome, Mozilla Firefox, Microsoft Edge, or Safari.

---

## 7. Installation & XAMPP Setup

1. **Place Project in `htdocs`**:
   Ensure the project folder is placed directly inside your XAMPP web root:
   ```text
   C:\xampp\htdocs\smart-library\
   ```

2. **Start Services**:
   Open the **XAMPP Control Panel** and click **Start** for both **Apache** and **MySQL**.

3. **Import Database**:
   - Open your browser and navigate to: [http://localhost/phpmyadmin/](http://localhost/phpmyadmin/)
   - Click on the **Import** tab.
   - Choose the file: `database/smart_library.sql`
   - Click **Go**. This automatically sets up the `smart_library` database, all 21 tables, and rich seed data.

4. **Launch Application**:
   Open [http://localhost/smart-library/](http://localhost/smart-library/) in your browser.

---

## 8. Default Login Credentials

Password for **every** default account: **`Password@123`**

| Role | Email / Username | Password |
| :--- | :--- | :--- |
| **Admin** | `admin@library.com` | `Password@123` |
| **Admin 2** | `admin1@gmail.com` | `Password@123` |
| **Librarian** | `ravi.librarian@library.com` | `Password@123` |
| **Librarian** | `jay23@gmail.com` | `Password@123` |
| **Student (Aarav)** | `aarav@student.com` | `Password@123` |
| **Student (Pavan)** | `pavan61@gmail.com` | `Password@123` |
| **Student (Anushka)** | `anushka65@gmail.com` | `Password@123` |

---

## 9. Folder Structure

```text
smart-library/
├── config/
│   └── database.php                # PDO connection parameters
├── admin/
│   ├── dashboard.php               # Admin stats, physical/virtual charts & feeds
│   ├── books.php                   # Physical book inventory CRUD
│   ├── digital-books.php           # Digital e-book management & PDF upload
│   ├── students.php                # Student user & enrollment management
│   ├── staff.php                   # Staff & librarian management
│   ├── categories.php              # Category management (17 default genres)
│   ├── authors.php                 # Authors directory
│   ├── publishers.php              # Publishers directory
│   ├── issues.php                  # Active and completed physical book issues
│   ├── returns.php                 # Book returns and late fine calculator
│   ├── reservations.php            # Physical reservations manager
│   ├── fines.php                   # Fine management (mark paid / waive)
│   ├── payments.php                # Payment records
│   ├── reports.php                 # 17 Report types (Physical & Virtual) + CSV export
│   ├── settings.php                # System circulation parameters
│   └── profile.php                 # Admin personal settings
├── librarian/
│   ├── dashboard.php               # Daily checkout/checkin metrics & quick actions
│   ├── issue-book.php              # Book issue validation & processing
│   ├── return-book.php             # Return processing & fine calculation
│   ├── books.php                   # Catalog search
│   ├── digital-books.php           # Digital resources & reader usage monitor
│   ├── students.php                # Student registration & borrowing history
│   ├── reservations.php            # Reservations processing
│   ├── fines.php                   # Fine collection
│   ├── reports.php                 # Daily circulation reporting
│   └── profile.php                 # Librarian profile
├── student/
│   ├── dashboard.php               # Unified student hub (Current books, continue reading)
│   ├── books.php                   # Unified Catalog (All, Physical, Virtual)
│   ├── virtual-library.php         # Genre filter pills, continue reading, e-book cards
│   ├── read-book.php               # In-browser interactive PDF reader
│   ├── serve-pdf.php               # Secure authenticated PDF stream endpoint
│   ├── save-progress.php           # AJAX progress, bookmarks, and favorites handler
│   ├── reading-history.php         # Reading sessions, % completed, saved bookmarks
│   ├── favorites.php               # Saved digital and physical favorites
│   ├── issued-books.php            # Active book checkouts & renewal
│   ├── reservations.php            # Physical reserve status
│   ├── fines.php                   # Outstanding & paid fines
│   ├── notifications.php           # Notification inbox
│   └── profile.php                 # Student profile & password change
├── auth/
│   ├── login.php                   # Secure session authentication
│   ├── logout.php                  # Session destruction
│   ├── forgot-password.php         # Password reset request
│   └── reset-password.php          # Password update verification
├── includes/
│   ├── header.php                  # Common HTML head and shell wrapper
│   ├── footer.php                  # Common footer and scripts
│   ├── sidebar.php                 # Dynamic role-based navigation sidebar
│   ├── navbar.php                  # Topbar with profile dropdown & notification counter
│   ├── auth-check.php              # Session authorization guard
│   └── functions.php               # Core helper functions, CSRF, formatting, audit logs
├── assets/
│   ├── css/style.css               # Design system tokens, cards, badges, reader UI
│   └── js/script.js                # Dynamic UI, modal triggers, password toggles
├── uploads/
│   ├── digital-books/              # Protected storage for uploaded PDF e-books
│   ├── books/                      # Book cover images
│   └── profiles/                   # User profile avatars
├── database/
│   └── smart_library.sql           # Schema definition & 15 sample digital books
├── README.md                       # Comprehensive project documentation
└── index.php                       # Role-based landing redirector
```

---

## 10. Database Schema Description

The system employs **21 normalized tables**:
- `users`: Universal login identities (`admin`, `librarian`, `student`).
- `students`: Academic metadata (`roll_number`, `course`, `department`, `max_books`).
- `staff`: Librarian shifts, designations, and join dates.
- `categories`, `authors`, `publishers`: Relational catalog taxonomies.
- `books`: Physical inventory records (`isbn`, `shelf_number`, `total_copies`, `available_copies`).
- `book_copies`: Barcode-level physical copy status.
- `book_issues`, `book_returns`: Circulation transactions and fine tracking.
- `reservations`: Waitlist queue for unavailable physical titles.
- `fines`, `payments`: Financial penalty ledger.
- `digital_books`: Digital catalog (`file_path`, `file_format`, `pages`, `access_type`, `view_count`, `read_count`).
- `reading_history`: Student digital reading state (`last_page`, `progress_percent`, `reading_status`).
- `bookmarks`: Page-specific notes and timestamps per student.
- `favorites`: Student-bookmarked physical and digital favorites.
- `library_attendance`: Physical library visit logs.
- `notifications`: User inbox alerts.
- `library_settings`: Configurable borrow period, daily fine rate, and maximum fine caps.
- `activity_logs`: System audit trail.

---

## 11. Security Implementation

- **Direct PDF Exposure Protection**: Uploaded PDFs are stored in `/uploads/digital-books/` and served strictly through `student/serve-pdf.php` after verifying the user's session and permissions.
- **Directory Traversal Mitigation**: File paths are validated with `realpath()` and `str_starts_with()` checks.
- **Server-Side File Verification**: PDF uploads verify MIME type via `finfo_file` (not relying on file extensions) and rename files to randomized strings.
- **CSRF Tokens**: Form submissions require a valid HMAC session token.
- **SQL Injection Prevention**: All queries use PDO prepared statements with bound parameters.
- **XSS Protection**: User output is escaped via `e()` (`htmlspecialchars`).

---

## 12. Testing Instructions

1. **Test Student Experience**:
   - Log in as `aarav@student.com` (password: `Password@123`).
   - Open **Virtual Library** in the sidebar.
   - Filter by genre pills (e.g., *Computer Science* or *Science Fiction*).
   - Click **Read Online** on any book (e.g. *Clean Code: Digital Handbook*).
   - Test Zoom In / Zoom Out, Left/Right arrow keys, and add a Bookmark on page 2.
   - Return to **My Dashboard**; verify the *Continue Reading* shelf displays the book with progress!
   - Open **Unified Catalog** (`student/books.php`) and toggle *All*, *Physical*, and *Virtual*.

2. **Test Admin Capabilities**:
   - Log in as `admin@library.com`.
   - Open **Digital Books** in the sidebar.
   - Click **Upload New Digital Book** to add a new PDF.
   - Open **Dashboard** to see the Virtual Library analytics and category distribution charts.
   - Open **Reports** and export any physical or virtual report to **CSV**.

3. **Test Librarian Circulation**:
   - Log in as `ravi.librarian@library.com`.
   - Go to **Digital Books** to monitor student usage and online readers.
   - Issue physical books or process returns with automatic fine calculation.
