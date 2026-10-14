```sql
CREATE DATABASE IF NOT EXISTS college_placement;
USE college_placement;

CREATE TABLE IF NOT EXISTS Department (
    department_id INT PRIMARY KEY AUTO_INCREMENT,
    department_name VARCHAR(100) NOT NULL UNIQUE
);

CREATE TABLE IF NOT EXISTS Student (
    student_id INT PRIMARY KEY AUTO_INCREMENT,
    name VARCHAR(100) NOT NULL,
    email VARCHAR(100) UNIQUE,
    phone VARCHAR(15),
    cgpa DECIMAL(3,2),
    graduation_year INT,
    department_id INT,
    FOREIGN KEY (department_id)
        REFERENCES Department(department_id)
);

CREATE TABLE IF NOT EXISTS Company (
    company_id INT PRIMARY KEY AUTO_INCREMENT,
    company_name VARCHAR(100) NOT NULL UNIQUE,
    location VARCHAR(100),
    industry VARCHAR(100)
);

CREATE TABLE IF NOT EXISTS Job (
    job_id INT PRIMARY KEY AUTO_INCREMENT,
    company_id INT NOT NULL,
    job_role VARCHAR(100) NOT NULL,
    package DECIMAL(10,2),
    min_cgpa DECIMAL(3,2),
    job_type VARCHAR(50),
    FOREIGN KEY (company_id)
        REFERENCES Company(company_id)
);

CREATE TABLE IF NOT EXISTS Placement_Drive (
    drive_id INT PRIMARY KEY AUTO_INCREMENT,
    job_id INT NOT NULL,
    drive_date DATE NOT NULL,
    deadline DATE NOT NULL,
    FOREIGN KEY (job_id)
        REFERENCES Job(job_id)
);

CREATE TABLE IF NOT EXISTS Application (
    application_id INT PRIMARY KEY AUTO_INCREMENT,
    student_id INT NOT NULL,
    drive_id INT NOT NULL,
    application_date DATE NOT NULL,
    status VARCHAR(30) DEFAULT 'Applied',
    FOREIGN KEY (student_id)
        REFERENCES Student(student_id),
    FOREIGN KEY (drive_id)
        REFERENCES Placement_Drive(drive_id),
    UNIQUE (student_id, drive_id)
);

CREATE TABLE IF NOT EXISTS Selection_Round (
    round_id INT PRIMARY KEY AUTO_INCREMENT,
    application_id INT NOT NULL,
    round_name VARCHAR(50) NOT NULL,
    round_date DATE,
    result VARCHAR(30),
    FOREIGN KEY (application_id)
        REFERENCES Application(application_id)
);

CREATE TABLE IF NOT EXISTS Placement_Result (
    result_id INT PRIMARY KEY AUTO_INCREMENT,
    application_id INT NOT NULL,
    final_status VARCHAR(30) NOT NULL,
    joining_date DATE,
    FOREIGN KEY (application_id)
        REFERENCES Application(application_id),
    UNIQUE (application_id)
);
```