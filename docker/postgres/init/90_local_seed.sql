-- Local Docker seed data. Safe for a brand-new local database.

UPDATE users
SET password_hash = '$2y$10$.Vui0cgGcwTC7JhhS9enz..4cvZ6falNj8ELsQPt9miKCHG0JntCW'
WHERE email = 'admin@academy.edu';

INSERT INTO user_roles (user_id, role)
SELECT id, role
FROM users
ON CONFLICT DO NOTHING;

INSERT INTO sections (name, grade_level, adviser_id, capacity)
VALUES
    ('Joseph', 'Preschool', NULL, 40),
    ('Mary', 'Kindergarten', NULL, 40),
    ('Eagle', '1', NULL, 40),
    ('Mango', '2', NULL, 40),
    ('Love', '3', NULL, 40),
    ('Mercury', '4', NULL, 40),
    ('Narra', '5', NULL, 40),
    ('Rizal', '6', NULL, 40)
ON CONFLICT DO NOTHING;
