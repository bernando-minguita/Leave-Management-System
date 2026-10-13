-- ---------------------------------------------------------------------------
-- Sample leave records - ALL dates are relative to CURDATE().
--
-- Spreads across every status the dashboard needs to demonstrate:
--   * currently away (on leave)
--   * returning today (3)
--   * returning tomorrow (5)
--   * returning this week (12 total incl. today/tomorrow)
--   * overdue (2)
--   * leave starting today / this week
--   * planned (future), in 3-day steps out to January 2027
--   * returned (history)
--   * cancelled
--
-- The curated rows below keep the dashboard KPIs exactly as designed; the
-- PLANNED block at the end adds the long forward runway without touching them.
-- ---------------------------------------------------------------------------

INSERT INTO leave_records
 (driver_id, leave_type_id, last_working_date, leave_start_date, ticket_date,
  expected_arrival_date, actual_arrival_date, actual_arrival_time, destination,
  reason, remarks, return_remarks, cancelled_reason, status, created_by, updated_by, created_at)
SELECT
  d.id, lt.id,
  DATE_SUB(CURDATE(), INTERVAL 2 DAY), DATE_SUB(CURDATE(), INTERVAL 2 DAY), DATE_SUB(CURDATE(), INTERVAL 3 DAY),
  DATE_ADD(CURDATE(), INTERVAL 8 DAY), NULL, NULL, 'Philippines',
  'Annual home leave', 'SAMPLE DATA', NULL, NULL,
  'on_leave', 1, 1, DATE_SUB(NOW(), INTERVAL 6 DAY)
FROM drivers d JOIN leave_types lt ON lt.name = 'Annual Vacation'
WHERE d.badge_number = 'B-001'
  AND NOT EXISTS (SELECT 1 FROM leave_records x WHERE x.driver_id = d.id);

INSERT INTO leave_records
 (driver_id, leave_type_id, last_working_date, leave_start_date, ticket_date,
  expected_arrival_date, actual_arrival_date, actual_arrival_time, destination,
  reason, remarks, return_remarks, cancelled_reason, status, created_by, updated_by, created_at)
SELECT
  d.id, lt.id,
  DATE_SUB(CURDATE(), INTERVAL 4 DAY), DATE_SUB(CURDATE(), INTERVAL 4 DAY), DATE_SUB(CURDATE(), INTERVAL 5 DAY),
  DATE_ADD(CURDATE(), INTERVAL 6 DAY), NULL, NULL, 'India',
  'Family function', 'SAMPLE DATA', NULL, NULL,
  'on_leave', 1, 1, DATE_SUB(NOW(), INTERVAL 8 DAY)
FROM drivers d JOIN leave_types lt ON lt.name = 'Annual Vacation'
WHERE d.badge_number = 'B-003'
  AND NOT EXISTS (SELECT 1 FROM leave_records x WHERE x.driver_id = d.id);

INSERT INTO leave_records
 (driver_id, leave_type_id, last_working_date, leave_start_date, ticket_date,
  expected_arrival_date, actual_arrival_date, actual_arrival_time, destination,
  reason, remarks, return_remarks, cancelled_reason, status, created_by, updated_by, created_at)
SELECT
  d.id, lt.id,
  DATE_SUB(CURDATE(), INTERVAL 1 DAY), DATE_SUB(CURDATE(), INTERVAL 1 DAY), DATE_SUB(CURDATE(), INTERVAL 2 DAY),
  DATE_ADD(CURDATE(), INTERVAL 5 DAY), NULL, NULL, 'Pakistan',
  'Home leave', 'SAMPLE DATA', NULL, NULL,
  'on_leave', 1, 1, DATE_SUB(NOW(), INTERVAL 3 DAY)
FROM drivers d JOIN leave_types lt ON lt.name = 'Annual Vacation'
WHERE d.badge_number = 'B-004'
  AND NOT EXISTS (SELECT 1 FROM leave_records x WHERE x.driver_id = d.id);

INSERT INTO leave_records
 (driver_id, leave_type_id, last_working_date, leave_start_date, ticket_date,
  expected_arrival_date, actual_arrival_date, actual_arrival_time, destination,
  reason, remarks, return_remarks, cancelled_reason, status, created_by, updated_by, created_at)
SELECT
  d.id, lt.id,
  DATE_SUB(CURDATE(), INTERVAL 3 DAY), DATE_SUB(CURDATE(), INTERVAL 3 DAY), DATE_SUB(CURDATE(), INTERVAL 4 DAY),
  DATE_SUB(CURDATE(), INTERVAL 1 DAY), NULL, NULL, 'Nepal',
  'Emergency travel', 'SAMPLE DATA - OVERDUE', NULL, NULL,
  'overdue', 1, 1, DATE_SUB(NOW(), INTERVAL 7 DAY)
FROM drivers d JOIN leave_types lt ON lt.name = 'Emergency Leave'
WHERE d.badge_number = 'B-018'
  AND NOT EXISTS (SELECT 1 FROM leave_records x WHERE x.driver_id = d.id);

INSERT INTO leave_records
 (driver_id, leave_type_id, last_working_date, leave_start_date, ticket_date,
  expected_arrival_date, actual_arrival_date, actual_arrival_time, destination,
  reason, remarks, return_remarks, cancelled_reason, status, created_by, updated_by, created_at)
SELECT
  d.id, lt.id,
  DATE_SUB(CURDATE(), INTERVAL 5 DAY), DATE_SUB(CURDATE(), INTERVAL 5 DAY), DATE_SUB(CURDATE(), INTERVAL 6 DAY),
  DATE_SUB(CURDATE(), INTERVAL 2 DAY), NULL, NULL, 'India',
  'Medical reasons', 'SAMPLE DATA - OVERDUE', NULL, NULL,
  'overdue', 1, 1, DATE_SUB(NOW(), INTERVAL 9 DAY)
FROM drivers d JOIN leave_types lt ON lt.name = 'Sick Leave'
WHERE d.badge_number = 'B-010'
  AND NOT EXISTS (SELECT 1 FROM leave_records x WHERE x.driver_id = d.id);

-- RETURNING LATER THIS WEEK (days +2 .. +6) - completes the 12 this week

INSERT INTO leave_records
 (driver_id, leave_type_id, last_working_date, leave_start_date, ticket_date, expected_arrival_date,
  actual_arrival_date, actual_arrival_time, destination, reason, remarks, return_remarks, cancelled_reason, status, created_by, updated_by, created_at)
SELECT d.id, lt.id, DATE_SUB(CURDATE(), INTERVAL 8 DAY), DATE_SUB(CURDATE(), INTERVAL 8 DAY), DATE_SUB(CURDATE(), INTERVAL 9 DAY),
  DATE_ADD(CURDATE(), INTERVAL 2 DAY), NULL, NULL, 'Saudi Arabia', 'Unpaid leave', 'SAMPLE DATA - RETURNING THIS WEEK', NULL, NULL,
  'on_leave', 1, 1, DATE_SUB(NOW(), INTERVAL 12 DAY)
FROM drivers d JOIN leave_types lt ON lt.name='Unpaid Leave'
WHERE d.badge_number='B-002' AND NOT EXISTS (SELECT 1 FROM leave_records x WHERE x.driver_id=d.id);

INSERT INTO leave_records
 (driver_id, leave_type_id, last_working_date, leave_start_date, ticket_date, expected_arrival_date,
  actual_arrival_date, actual_arrival_time, destination, reason, remarks, return_remarks, cancelled_reason, status, created_by, updated_by, created_at)
SELECT d.id, lt.id, DATE_SUB(CURDATE(), INTERVAL 5 DAY), DATE_SUB(CURDATE(), INTERVAL 5 DAY), DATE_SUB(CURDATE(), INTERVAL 6 DAY),
  DATE_ADD(CURDATE(), INTERVAL 3 DAY), NULL, NULL, 'India', 'Home leave', 'SAMPLE DATA - RETURNING THIS WEEK', NULL, NULL,
  'on_leave', 1, 1, DATE_SUB(NOW(), INTERVAL 9 DAY)
FROM drivers d JOIN leave_types lt ON lt.name='Annual Vacation'
WHERE d.badge_number='B-006' AND NOT EXISTS (SELECT 1 FROM leave_records x WHERE x.driver_id=d.id);

INSERT INTO leave_records
 (driver_id, leave_type_id, last_working_date, leave_start_date, ticket_date, expected_arrival_date,
  actual_arrival_date, actual_arrival_time, destination, reason, remarks, return_remarks, cancelled_reason, status, created_by, updated_by, created_at)
SELECT d.id, lt.id, DATE_SUB(CURDATE(), INTERVAL 7 DAY), DATE_SUB(CURDATE(), INTERVAL 7 DAY), DATE_SUB(CURDATE(), INTERVAL 8 DAY),
  DATE_ADD(CURDATE(), INTERVAL 4 DAY), NULL, NULL, 'Philippines', 'Annual home leave', 'SAMPLE DATA - RETURNING THIS WEEK', NULL, NULL,
  'on_leave', 1, 1, DATE_SUB(NOW(), INTERVAL 11 DAY)
FROM drivers d JOIN leave_types lt ON lt.name='Annual Vacation'
WHERE d.badge_number='B-009' AND NOT EXISTS (SELECT 1 FROM leave_records x WHERE x.driver_id=d.id);

INSERT INTO leave_records
 (driver_id, leave_type_id, last_working_date, leave_start_date, ticket_date, expected_arrival_date,
  actual_arrival_date, actual_arrival_time, destination, reason, remarks, return_remarks, cancelled_reason, status, created_by, updated_by, created_at)
SELECT d.id, lt.id, DATE_SUB(CURDATE(), INTERVAL 4 DAY), DATE_SUB(CURDATE(), INTERVAL 4 DAY), DATE_SUB(CURDATE(), INTERVAL 5 DAY),
  DATE_ADD(CURDATE(), INTERVAL 5 DAY), NULL, NULL, 'India', 'Medical treatment', 'SAMPLE DATA - RETURNING THIS WEEK', NULL, NULL,
  'on_leave', 1, 1, DATE_SUB(NOW(), INTERVAL 8 DAY)
FROM drivers d JOIN leave_types lt ON lt.name='Sick Leave'
WHERE d.badge_number='B-011' AND NOT EXISTS (SELECT 1 FROM leave_records x WHERE x.driver_id=d.id);

INSERT INTO leave_records
 (driver_id, leave_type_id, last_working_date, leave_start_date, ticket_date, expected_arrival_date,
  actual_arrival_date, actual_arrival_time, destination, reason, remarks, return_remarks, cancelled_reason, status, created_by, updated_by, created_at)
SELECT d.id, lt.id, DATE_SUB(CURDATE(), INTERVAL 6 DAY), DATE_SUB(CURDATE(), INTERVAL 6 DAY), DATE_SUB(CURDATE(), INTERVAL 7 DAY),
  DATE_ADD(CURDATE(), INTERVAL 6 DAY), NULL, NULL, 'Pakistan', 'Home leave', 'SAMPLE DATA - RETURNING THIS WEEK', NULL, NULL,
  'on_leave', 1, 1, DATE_SUB(NOW(), INTERVAL 10 DAY)
FROM drivers d JOIN leave_types lt ON lt.name='Annual Vacation'
WHERE d.badge_number='B-020' AND NOT EXISTS (SELECT 1 FROM leave_records x WHERE x.driver_id=d.id);

-- LEAVE STARTING TODAY / THIS WEEK

INSERT INTO leave_records
 (driver_id, leave_type_id, last_working_date, leave_start_date, ticket_date, expected_arrival_date,
  actual_arrival_date, actual_arrival_time, destination, reason, remarks, return_remarks, cancelled_reason, status, created_by, updated_by, created_at)
SELECT d.id, lt.id, CURDATE(), CURDATE(), DATE_ADD(CURDATE(), INTERVAL 1 DAY),
  DATE_ADD(CURDATE(), INTERVAL 14 DAY), NULL, NULL, 'Philippines', 'Annual home leave', 'SAMPLE DATA - STARTING TODAY', NULL, NULL,
  'on_leave', 1, 1, DATE_SUB(NOW(), INTERVAL 2 DAY)
FROM drivers d JOIN leave_types lt ON lt.name='Annual Vacation'
WHERE d.badge_number='B-014' AND NOT EXISTS (SELECT 1 FROM leave_records x WHERE x.driver_id=d.id);

INSERT INTO leave_records
 (driver_id, leave_type_id, last_working_date, leave_start_date, ticket_date, expected_arrival_date,
  actual_arrival_date, actual_arrival_time, destination, reason, remarks, return_remarks, cancelled_reason, status, created_by, updated_by, created_at)
SELECT d.id, lt.id, DATE_ADD(CURDATE(), INTERVAL 2 DAY), DATE_ADD(CURDATE(), INTERVAL 2 DAY), DATE_ADD(CURDATE(), INTERVAL 1 DAY),
  DATE_ADD(CURDATE(), INTERVAL 16 DAY), NULL, NULL, 'India', 'Home leave', 'SAMPLE DATA - STARTING THIS WEEK', NULL, NULL,
  'planned', 1, 1, DATE_SUB(NOW(), INTERVAL 1 DAY)
FROM drivers d JOIN leave_types lt ON lt.name='Annual Vacation'
WHERE d.badge_number='B-017' AND NOT EXISTS (SELECT 1 FROM leave_records x WHERE x.driver_id=d.id);

-- RETURNED (completed trips = leave history)

INSERT INTO leave_records
 (driver_id, leave_type_id, last_working_date, leave_start_date, ticket_date, expected_arrival_date,
  actual_arrival_date, actual_arrival_time, destination, reason, remarks, return_remarks, cancelled_reason, status, created_by, updated_by, created_at)
SELECT d.id, lt.id, DATE_SUB(CURDATE(), INTERVAL 75 DAY), DATE_SUB(CURDATE(), INTERVAL 75 DAY), DATE_SUB(CURDATE(), INTERVAL 76 DAY),
  DATE_SUB(CURDATE(), INTERVAL 63 DAY), DATE_SUB(CURDATE(), INTERVAL 63 DAY), '14:30:00', 'Philippines', 'Annual home leave',
  'SAMPLE DATA', 'Landed on time', NULL, 'returned', 1, 1, DATE_SUB(NOW(), INTERVAL 80 DAY)
FROM drivers d JOIN leave_types lt ON lt.name='Annual Vacation'
WHERE d.badge_number='B-001' AND NOT EXISTS (SELECT 1 FROM leave_records x WHERE x.driver_id=d.id AND x.status='returned');

INSERT INTO leave_records
 (driver_id, leave_type_id, last_working_date, leave_start_date, ticket_date, expected_arrival_date,
  actual_arrival_date, actual_arrival_time, destination, reason, remarks, return_remarks, cancelled_reason, status, created_by, updated_by, created_at)
SELECT d.id, lt.id, DATE_SUB(CURDATE(), INTERVAL 45 DAY), DATE_SUB(CURDATE(), INTERVAL 45 DAY), DATE_SUB(CURDATE(), INTERVAL 46 DAY),
  DATE_SUB(CURDATE(), INTERVAL 33 DAY), DATE_SUB(CURDATE(), INTERVAL 32 DAY), '09:15:00', 'India', 'Family emergency',
  'SAMPLE DATA', 'Arrived one day late', NULL, 'returned', 1, 1, DATE_SUB(NOW(), INTERVAL 50 DAY)
FROM drivers d JOIN leave_types lt ON lt.name='Emergency Leave'
WHERE d.badge_number='B-003' AND NOT EXISTS (SELECT 1 FROM leave_records x WHERE x.driver_id=d.id AND x.status='returned');

INSERT INTO leave_records
 (driver_id, leave_type_id, last_working_date, leave_start_date, ticket_date, expected_arrival_date,
  actual_arrival_date, actual_arrival_time, destination, reason, remarks, return_remarks, cancelled_reason, status, created_by, updated_by, created_at)
SELECT d.id, lt.id, DATE_SUB(CURDATE(), INTERVAL 20 DAY), DATE_SUB(CURDATE(), INTERVAL 20 DAY), DATE_SUB(CURDATE(), INTERVAL 21 DAY),
  DATE_SUB(CURDATE(), INTERVAL 8 DAY), DATE_SUB(CURDATE(), INTERVAL 8 DAY), '22:05:00', 'Pakistan', 'Home leave',
  'SAMPLE DATA', 'Returned on the expected date', NULL, 'returned', 1, 1, DATE_SUB(NOW(), INTERVAL 24 DAY)
FROM drivers d JOIN leave_types lt ON lt.name='Annual Vacation'
WHERE d.badge_number='B-005' AND NOT EXISTS (SELECT 1 FROM leave_records x WHERE x.driver_id=d.id AND x.status='returned');

-- CANCELLED

INSERT INTO leave_records
 (driver_id, leave_type_id, last_working_date, leave_start_date, ticket_date, expected_arrival_date,
  actual_arrival_date, actual_arrival_time, destination, reason, remarks, return_remarks, cancelled_reason, status, created_by, updated_by, created_at)
SELECT d.id, lt.id, DATE_ADD(CURDATE(), INTERVAL 5 DAY), DATE_ADD(CURDATE(), INTERVAL 5 DAY), DATE_ADD(CURDATE(), INTERVAL 4 DAY),
  DATE_ADD(CURDATE(), INTERVAL 18 DAY), NULL, NULL, 'India', 'Home leave', 'SAMPLE DATA', NULL, 'Trip postponed by driver',
  'cancelled', 1, 1, DATE_SUB(NOW(), INTERVAL 3 DAY)
FROM drivers d JOIN leave_types lt ON lt.name='Annual Vacation'
WHERE d.badge_number='B-007' AND NOT EXISTS (SELECT 1 FROM leave_records x WHERE x.driver_id=d.id AND x.status='cancelled');

-- ---------------------------------------------------------------------------
-- PLANNED LEAVE RUNNING OUT TO JANUARY 2027
--
-- One future trip per driver B-021..B-050, so Expected Returns, the calendar
-- and the "planned" filter have a long forward runway instead of stopping at
-- the next couple of weeks.
--
-- Dates stay relative to CURDATE() (the seed must work whenever it is run)
-- and step by 3 days, where n = badge number - 21, i.e. 0..29:
--
--   expected return = CURDATE() + 40 + n * 3          ->  +40 .. +127 days
--   leave start      = expected - 14                   ->  +26 .. +113 days
--
-- Installed on the seed date that spreads arrivals from early November 2026
-- to 30 January 2027 - inside January, so the runway the admin asks for is
-- met while every leave start is still in the future and each row keeps
-- status 'planned', leaving the curated dashboard numbers above untouched.
--
-- Guarded, so re-running the seed never duplicates a trip.
-- ---------------------------------------------------------------------------

INSERT INTO leave_records
 (driver_id, leave_type_id, last_working_date, leave_start_date, ticket_date,
  expected_arrival_date, actual_arrival_date, actual_arrival_time, destination,
  reason, remarks, return_remarks, cancelled_reason, status, created_by, updated_by, created_at)
SELECT
  t.driver_id, t.type_id, t.last_working, t.leave_start, t.ticket,
  t.expected, NULL, NULL, t.destination,
  'Annual home leave', 'SAMPLE DATA - PLANNED', NULL, NULL,
  'planned', 1, 1, DATE_SUB(NOW(), INTERVAL 2 DAY)
FROM (
    SELECT
      d.id AS driver_id,
      lt.id AS type_id,
      DATE_ADD(CURDATE(), INTERVAL (24 + (CAST(SUBSTRING(d.badge_number, 3) AS UNSIGNED) - 21) * 3) DAY) AS ticket,
      DATE_ADD(CURDATE(), INTERVAL (25 + (CAST(SUBSTRING(d.badge_number, 3) AS UNSIGNED) - 21) * 3) DAY) AS last_working,
      DATE_ADD(CURDATE(), INTERVAL (26 + (CAST(SUBSTRING(d.badge_number, 3) AS UNSIGNED) - 21) * 3) DAY) AS leave_start,
      DATE_ADD(CURDATE(), INTERVAL (40 + (CAST(SUBSTRING(d.badge_number, 3) AS UNSIGNED) - 21) * 3) DAY) AS expected,
      ELT(1 + ((CAST(SUBSTRING(d.badge_number, 3) AS UNSIGNED) - 21) MOD 6),
          'Philippines', 'India', 'Pakistan', 'Nepal', 'Bangladesh', 'Saudi Arabia') AS destination
    FROM drivers d
    JOIN leave_types lt
      ON lt.name = ELT(1 + ((CAST(SUBSTRING(d.badge_number, 3) AS UNSIGNED) - 21) MOD 4),
                      'Annual Vacation', 'Sick Leave', 'Emergency Leave', 'Unpaid Leave')
    WHERE d.badge_number >= 'B-021'
      AND d.badge_number <= 'B-050'
) t
WHERE NOT EXISTS (
    SELECT 1 FROM leave_records x
    WHERE x.driver_id = t.driver_id
      AND x.remarks = 'SAMPLE DATA - PLANNED'
);


