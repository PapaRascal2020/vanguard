CREATE DATABASE laravel;
CREATE USER laravel_user WITH ENCRYPTED PASSWORD 'your_password_here';
GRANT ALL PRIVILEGES ON DATABASE laravel TO laravel_user;

-- You can add more initialization commands here if needed
-- For example, creating tables, inserting initial data, etc.
