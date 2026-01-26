- There are 6 roles here
1. Admins
2. Key Teachers
3. ICT Coordinators
4. Registrar
5. Adviser
6. Subject Teacher

Primary Color: #085019

NOTES:
- Admin settings not working, could be changed. (is it necessary?)
- Adviser will just recieved the class lists. They can enroll and review enrollments too.
- Key Teacher settings does not work. I still don't know what is important to put there.
- ICT Coordinator system access does not work. I was thinking that the system access is still needed? Enrollment form needs to be updated and workable.
- Registrar pages is all good. Has bugs
- Subject Teacher pages does not work. Needs to be functional

Bugs Detected:
- Even though the students enrollment is still pending you can still be in a section list. Found in Student Management/Registrar
- Some filters dropdown does not work right
- Registrar dashboard confirmed enrollment is still zero despite I have accepted students as confirmed. How come its like that?
- There is a bug too if I logout the localhost is asking "Are you sure you want to logout?" 2-3 times. I think its being duplicated because there is inside in auth.js and inside that page particularly in the script. Should I choose the auth.js or separate pages for each?
- Bugs many bugs in csv feature

Added Feature:
- Already added dashboard functionality that pulls the api of employees.php and users.php, so it know all the active users, how many employees and more.
- Added feature of registrar dashboard, it would be important to add a school year dropdown
- Added CSV import in Registrar, enrollment form