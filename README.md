\# PlacementHub 🎓



\*\*A College Placement Management System built with PHP and MySQL.\*\*



PlacementHub helps manage campus recruitment activities in one place, from student records and company details to job opportunities, applications, selection rounds, and final placement results.



\## ✨ Features



\- \*\*Dashboard:\*\* View placement statistics and recent applications.

\- \*\*Student Management:\*\* Add, edit, search, and manage student records.

\- \*\*Company Management:\*\* Maintain company information and track available jobs.

\- \*\*Job Management:\*\* Manage job roles, packages, eligibility criteria, and placement drives.

\- \*\*Application Management:\*\* Record student applications, search records, and update application statuses.

\- \*\*Selection Rounds:\*\* Track aptitude tests, coding tests, technical interviews, HR interviews, and results.

\- \*\*Placement Results:\*\* Record final selection results, joining dates, and package information.

\- \*\*Database Integration:\*\* Store and retrieve placement data using MySQL/MariaDB.

\- \*\*Security Improvements:\*\* Use prepared statements, output escaping, and CSRF tokens for form submissions.



\## 🛠️ Technologies Used



\- PHP

\- MySQL / MariaDB

\- HTML5

\- CSS3

\- JavaScript

\- Git and GitHub

\- XAMPP for local development



\## 📁 Project Structure



```text

placementhub/

├── index.php

├── db.php

├── style.css

├── students.php

├── companies.php

├── jobs.php

├── applications.php

├── placements.php

├── rounds.php

└── .gitignore

```



\## ⚙️ Run Locally



\### Prerequisites



Install \[XAMPP](https://www.apachefriends.org/) with Apache, PHP, and MySQL/MariaDB support.



\### Setup



1\. Clone or download this repository.

2\. Place the project folder inside XAMPP's `htdocs` directory.

3\. Start Apache and MySQL from the XAMPP Control Panel.

4\. Open phpMyAdmin at `http://localhost/phpmyadmin/`.

5\. Create a database named `college\_placement`.

6\. Create the required tables using the project's database schema.

7\. Configure the database connection in `db.php`, or set the appropriate database environment variables.

8\. Open the application at:



&#x20;  `http://localhost/college\_placements/`



\*\*Note:\*\* A database schema or migration file should be included in the repository before presenting this as a fully reproducible setup. The local database backup is intentionally not included.



\## 🔐 Security Notes



\- Do not commit database passwords, private credentials, or production environment files.

\- Keep database backups out of the public repository.

\- Use a dedicated database user with limited privileges in production.

\- Configure HTTPS and production error handling when deploying.

\- Review all pages and database queries before using real student information.



\## 🚀 Future Improvements



\- User authentication and role-based access for students, placement officers, and administrators.

\- CSV export for placement reports.

\- Advanced analytics and department-wise placement statistics.

\- Improved validation and eligibility checks.

\- Responsive UI improvements.

\- Online deployment.



\## 🎯 Project Goal



The goal of PlacementHub is to simplify campus placement administration and provide a centralized system for tracking recruitment activities and student placement outcomes.



\## 👩‍💻 Development



This project is being developed and improved incrementally as a DBMS and web development project.



