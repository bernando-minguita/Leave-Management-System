-- ===========================================================================
-- LMS - sample data
--
-- Everything below is clearly marked "SAMPLE DATA" in the remarks column and
-- can be removed with:   php tools/remove-sample-data.php
--
-- Leave dates are written RELATIVE to CURDATE() so the dashboard always
-- demonstrates: drivers away now, returning today / tomorrow / this week and
-- overdue returns - whenever the database is installed.
-- ===========================================================================

SET NAMES utf8mb4;

-- ---------------------------------------------------------------------------
-- branches
-- ---------------------------------------------------------------------------
INSERT IGNORE INTO branches (branch_name, code, status) VALUES
  ('Dammam', 'DMM', 'active'),
  ('Riyadh', 'RUH', 'active'),
  ('Jeddah', 'JED', 'active'),
  ('Khobar', 'KHB', 'active');

-- ---------------------------------------------------------------------------
-- agencies
-- ---------------------------------------------------------------------------
INSERT IGNORE INTO agencies (agency_name, code, contact_person, mobile, email, address, remarks, status) VALUES
  ('Al Waha Manpower',   'AWM', 'Mr. Saeed Al Harbi',  '0501112233', 'ops@alwaha.example',   'Industrial City, Dammam', 'SAMPLE DATA', 'active'),
  ('Riyadh Transit Co.', 'RTC', 'Mr. Faisal Al Otaibi','0502223344', 'hr@riyadhtransit.example', 'Olaya, Riyadh',       'SAMPLE DATA', 'active'),
  ('Jeddah Logistics Sup.','JLS','Mr. Omar Zahrani',   '0503334455', 'info@jeddahlog.example',   'Al Hamra, Jeddah',    'SAMPLE DATA', 'active'),
  ('Eastern Fleet Svc.', 'EFS', 'Mr. Nasser Al Ghamdi','0504445556', 'contact@easternfleet.example','Dammam Corniche',  'SAMPLE DATA', 'active'),
  ('Najd Drivers Agency','NDA','Mr. Khalid Al Subaie','0505556677', 'support@najd.example',      'Al Nakheel, Riyadh',   'SAMPLE DATA', 'active');

-- ---------------------------------------------------------------------------
-- leave types (classification only - no balances, no entitlements)
-- ---------------------------------------------------------------------------
INSERT IGNORE INTO leave_types (name, description, status) VALUES
  ('Annual Vacation',   'Scheduled home leave / vacation',                 'active'),
  ('Sick Leave',        'Illness or medical treatment',                    'active'),
  ('Emergency Leave',   'Family or personal emergency',                    'active'),
  ('Unpaid Leave',      'Leave taken without pay (record keeping only)',   'active'),
  ('Other',             'Any other absence not covered above',             'active');

-- ---------------------------------------------------------------------------
-- settings
-- ---------------------------------------------------------------------------
INSERT IGNORE INTO settings (setting_key, setting_value, label, group_name) VALUES
  ('company_name',  'LMS Logistics',     'Company name',           'general'),
  ('return_window', '7',                        'Return window (days)',   'operations'),
  ('date_format',   'd M Y',                    'Display date format',    'general'),
  ('page_title',    'Driver Leave Management',  'Browser page title',     'general'),
  ('leave_percentage',    '7',  'Target leave percentage (%)', 'operations'),
  ('pending_window_days', '30', 'Pending LWD window (days)',   'operations'),
  -- Address Book status option lists (value|Label, comma separated):
  -- drivers.status / drivers.operational_status store the value on the left,
  -- every screen shows the label on the right. The value "active" must stay
  -- in employment_statuses - the KPIs count it.
  ('employment_statuses',
   'active|Active,resigned|Resigned,terminated|Terminated,inactive|Inactive',
   'Employment status options (value|Label, comma separated)', 'operations'),
  ('operational_statuses',
   'assigned|Assigned,idle|Idle,vacation|Vacation,training|Training,sick_gosi|Sick / GOSI,suspended|Suspended,absconded|Absconded',
   'Operational status options (value|Label, comma separated)', 'operations'),
  -- Destination Country on both leave forms. NOT value|Label: the country
  -- name is both the value and the label, which is what keeps the destination
  -- filter, the exports and every row written before this list existed
  -- working untouched. "Not set" is the form's empty option, not a member.
  -- Must stay under 500 characters (settings.setting_value is VARCHAR(500)).
  -- See Lookup::destinationCountries().
  ('destination_countries',
   'Saudi Arabia,United Arab Emirates,Qatar,Kuwait,Bahrain,Oman,Jordan,Iraq,Lebanon,Egypt,Sudan,Yemen,India,Pakistan,Nepal,Bangladesh,Sri Lanka,Philippines,Indonesia,Malaysia,Thailand,China,Japan,South Korea,Turkey,Kenya,Ethiopia,Nigeria,South Africa,United Kingdom,United States,Canada,Australia,Germany,France,Italy,Spain',
   'Destination country options (comma separated)', 'operations');
