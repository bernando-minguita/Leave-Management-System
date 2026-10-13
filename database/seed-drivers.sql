-- ---------------------------------------------------------------------------
-- 50 sample drivers (badge numbers B-001..B-050, marked SAMPLE DATA)
-- IQAMA values are fictional: they start with 3 (real IQAMAs start with 1 or 2).
-- ---------------------------------------------------------------------------
INSERT IGNORE INTO drivers
 (badge_number, driver_name, iqama_number, mobile_number, alternate_mobile, nationality,
  agency_id, branch_id, department, position, joining_date,
  emergency_contact_name, emergency_contact_number, address, city, remarks, status)
VALUES
 ('B-001','Ahmed Mohammed Ali',      '3451123456','0551000001','0551000111','Philippines', 1,1,'Transport','Heavy Truck Driver','2019-03-11','Mariam Ali','0551000111','Block 12, Al Adamah','Dammam','SAMPLE DATA','active'),
 ('B-002','Sami Abdullah Al Harbi',  '3451123457','0551000002','0551000112','Saudi Arabia',2,1,'Transport','Light Van Driver',  '2020-07-01','Fatima Harbi','0551000112','Al Murabaa','Riyadh','SAMPLE DATA','active'),
 ('B-003','Rajesh Kumar Nair',       '3451123458','0551000003','0551000113','India',       1,1,'Transport','Heavy Truck Driver','2018-01-20','Lakshmi Nair','0551000113','Al Faisaliyah','Dammam','SAMPLE DATA','active'),
 ('B-004','Mohammed Farhan Siddiq',  '3451123459','0551000004','0551000114','Pakistan',    4,2,'Transport','Tanker Driver',     '2017-11-05','Ayesha Siddiq','0551000114','Al Nakheel','Riyadh','SAMPLE DATA','active'),
 ('B-005','Joseph Bautista Cruz',    '3451123460','0551000005','0551000115','Philippines', 3,3,'Transport','Bus Driver',        '2021-02-14','Ana Cruz','0551000115','Al Rawdah','Jeddah','SAMPLE DATA','active'),
 ('B-006','Abdul Rahman Al Qahtani', '3451123461','0551000006','0551000116','Saudi Arabia',5,2,'Transport','Heavy Truck Driver','2016-05-30','Hessa Qahtani','0551000116','Al Sulimaniyah','Riyadh','SAMPLE DATA','active'),
 ('B-007','Sunil Prakash Menon',     '3451123462','0551000007','0551000117','India',       1,1,'Transport','Delivery Driver',   '2019-09-09','Devi Menon','0551000117','Al Shati','Dammam','SAMPLE DATA','active'),
 ('B-008','Khalid Saeed Al Zahrani', '3451123463','0551000008','0551000118','Saudi Arabia',3,3,'Transport','Light Van Driver',  '2022-04-18','Nouf Zahrani','0551000118','Al Hamra','Jeddah','SAMPLE DATA','active'),
 ('B-009','Carlo Ramirez Diaz',      '3451123464','0551000009','0551000119','Philippines',4,2,'Transport','Bus Driver',        '2020-12-01','Elena Diaz','0551000119','Al Yasmin','Riyadh','SAMPLE DATA','active'),
 ('B-010','Imran Hossain Chowdhury', '3451123465','0551000010','0551000120','Bangladesh',  1,1,'Transport','Tanker Driver',     '2018-06-25','Rahima Chowdhury','0551000120','Al Qatif Road','Dammam','SAMPLE DATA','active'),
 ('B-011','Fahad Mishal Al Otaibi',  '3451123466','0551000011','0551000121','Saudi Arabia',2,2,'Transport','Heavy Truck Driver','2015-08-12','Sarah Otaibi','0551000121','Al Malaz','Riyadh','SAMPLE DATA','active'),
 ('B-012','Anil Sharma Verma',       '3451123467','0551000012','0551000122','India',       5,2,'Transport','Delivery Driver',   '2021-10-03','Sunita Verma','0551000122','Al Muruj','Riyadh','SAMPLE DATA','active'),
 ('B-013','Ronel Aquino Beningo',    '3451123468','0551000013','0551000123','Philippines', 3,3,'Transport','Heavy Truck Driver','2019-05-21','Lorna Beningo','0551000123','Al Zahra','Jeddah','SAMPLE DATA','active'),
 ('B-014','Saif Nasser Al Mutairi',  '3451123469','0551000014','0551000124','Saudi Arabia',5,3,'Transport','Bus Driver',        '2017-03-08','Mona Mutairi','0551000124','Al Safa','Jeddah','SAMPLE DATA','active'),
 ('B-015','Muhammad Adil Iqbal',     '3451123470','0551000015','0551000125','Pakistan',    4,1,'Transport','Tanker Driver',     '2020-02-27','Zara Iqbal','0551000125','Al Khobar Corniche','Khobar','SAMPLE DATA','active'),
 ('B-016','Tesaloniya Ramos Joy',    '3451123471','0551000016','0551000126','Philippines', 3,1,'Transport','Light Van Driver',  '2022-01-15','Jomar Ramos','0551000126','Al Aqiq','Khobar','SAMPLE DATA','active'),
 ('B-017','Yousef Omar Al Ghamedi',  '3451123472','0551000017','0551000127','Saudi Arabia',2,3,'Transport','Heavy Truck Driver','2016-09-19','Reem Ghamedi','0551000127','Al Rowdah','Jeddah','SAMPLE DATA','active'),
 ('B-018','Bikram Thapa Gurung',     '3451123473','0551000018','0551000128','Nepal',       1,2,'Transport','Delivery Driver',   '2021-07-07','Sita Gurung','0551000128','Al Aridh','Riyadh','SAMPLE DATA','active'),
 ('B-019','Saleh Ali Al Subaie',     '3451123474','0551000019','0551000129','Saudi Arabia',5,1,'Transport','Bus Driver',        '2014-11-11','Norah Subaie','0551000129','Al Raka','Dammam','SAMPLE DATA','active'),
 ('B-020','Deepak Rajan Pillai',     '3451123475','0551000020','0551000130','India',       4,2,'Transport','Heavy Truck Driver','2023-03-02','Anju Pillai','0551000130','Al Wadi','Riyadh','SAMPLE DATA','active'),
 ('B-021','Nasser Talal Al Dosari',  '3451123476','0551000021','0551000131','Saudi Arabia',1,1,'Transport','Heavy Truck Driver','2018-08-14','Nouf Al Dosari','0551000131','Al Danah','Dammam','SAMPLE DATA','active'),
 ('B-022','Ricardo Mendoza Salas',   '3451123477','0551000022','0551000132','Philippines', 2,2,'Transport','Light Van Driver',  '2020-03-19','Lorna Salas','0551000132','Al Olaya','Riyadh','SAMPLE DATA','active'),
 ('B-023','Vikram Singh Rathore',    '3451123478','0551000023','0551000133','India',       3,3,'Transport','Tanker Driver',     '2017-06-23','Meera Rathore','0551000133','Al Baghdadia','Jeddah','SAMPLE DATA','active'),
 ('B-024','Mohammed Sultan Al Harthi','3451123479','0551000024','0551000134','Saudi Arabia',4,4,'Transport','Bus Driver',        '2019-11-02','Hala Al Harthi','0551000134','Al Rawabi','Khobar','SAMPLE DATA','active'),
 ('B-025','Joselito Reyes Dizon',    '3451123480','0551000025','0551000135','Philippines', 5,1,'Transport','Delivery Driver',   '2021-05-27','Cora Dizon','0551000135','Al Shati','Dammam','SAMPLE DATA','active'),
 ('B-026','Abdul Aziz Al Shammari',  '3451123481','0551000026','0551000136','Saudi Arabia',1,2,'Transport','Heavy Truck Driver','2016-02-11','Reema Al Shammari','0551000136','Al Malaz','Riyadh','SAMPLE DATA','active'),
 ('B-027','Sunil Kumar Yadav',       '3451123482','0551000027','0551000137','India',       2,3,'Transport','Light Van Driver',  '2022-09-08','Sunita Yadav','0551000137','Al Zahra','Jeddah','SAMPLE DATA','active'),
 ('B-028','Rohit Man Singh Limbu',   '3451123483','0551000028','0551000138','Nepal',       3,4,'Transport','Delivery Driver',   '2020-10-16','Kamala Limbu','0551000138','Al Aqiq','Khobar','SAMPLE DATA','active'),
 ('B-029','Faisal Bandar Al Qahtani','3451123484','0551000029','0551000139','Saudi Arabia',4,1,'Transport','Tanker Driver',     '2015-04-30','Sadah Qahtani','0551000139','Al Adamah','Dammam','SAMPLE DATA','active'),
 ('B-030','Emmanuel Reyes Ramos',    '3451123485','0551000030','0551000140','Philippines', 5,2,'Transport','Bus Driver',        '2019-01-22','Elma Ramos','0551000140','Al Murabaa','Riyadh','SAMPLE DATA','active'),
 ('B-031','Tanvir Hossain Mirza',    '3451123486','0551000031','0551000141','Bangladesh',  1,3,'Transport','Heavy Truck Driver','2018-12-05','Nasrin Mirza','0551000141','Al Hamra','Jeddah','SAMPLE DATA','active'),
 ('B-032','Yousef Khalid Al Garni',  '3451123487','0551000032','0551000142','Saudi Arabia',2,4,'Transport','Light Van Driver',  '2021-07-19','Abeer Al Garni','0551000142','Al Rawdah','Khobar','SAMPLE DATA','active'),
 ('B-033','Anwar Hadi Sulaiman',     '3451123488','0551000033','0551000143','Pakistan',    3,1,'Transport','Delivery Driver',   '2020-05-14','Rabia Sulaiman','0551000143','Al Qatif Road','Dammam','SAMPLE DATA','active'),
 ('B-034','Marlon Bautista Ocampo',  '3451123489','0551000034','0551000144','Philippines', 4,2,'Transport','Tanker Driver',     '2017-09-27','Grace Ocampo','0551000144','Al Yasmin','Riyadh','SAMPLE DATA','active'),
 ('B-035','Saud Mishal Al Shihri',   '3451123490','0551000035','0551000145','Saudi Arabia',5,3,'Transport','Bus Driver',        '2016-11-23','Lulwa Al Shihri','0551000145','Al Baghdadia','Jeddah','SAMPLE DATA','active'),
 ('B-036','Bikash Tamang Sherpa',    '3451123491','0551000036','0551000146','Nepal',       1,4,'Transport','Heavy Truck Driver','2022-02-09','Mingma Sherpa','0551000146','Al Rawabi','Khobar','SAMPLE DATA','active'),
 ('B-037','Rahul Dev Sharma',        '3451123492','0551000037','0551000147','India',       2,1,'Transport','Light Van Driver',  '2019-06-11','Anita Sharma','0551000147','Al Danah','Dammam','SAMPLE DATA','active'),
 ('B-038','Sultan Ahmed Al Otaibi',  '3451123493','0551000038','0551000148','Saudi Arabia',3,2,'Transport','Tanker Driver',     '2015-10-07','Hessa Al Otaibi','0551000148','Al Olaya','Riyadh','SAMPLE DATA','active'),
 ('B-039','Jefferson Lim Cruz',      '3451123494','0551000039','0551000149','Philippines', 4,3,'Transport','Delivery Driver',   '2021-03-25','Marites Cruz','0551000149','Al Zahra','Jeddah','SAMPLE DATA','active'),
 ('B-040','Kamrul Islam Sarker',     '3451123495','0551000040','0551000150','Bangladesh',  5,4,'Transport','Bus Driver',        '2018-04-18','Rokeya Sarker','0551000150','Al Aqiq','Khobar','SAMPLE DATA','active'),
 ('B-041','Mishaal Rashed Al Ketbi', '3451123496','0551000041','0551000151','Saudi Arabia',1,1,'Transport','Heavy Truck Driver','2017-01-30','Norah Al Ketbi','0551000151','Al Shati','Dammam','SAMPLE DATA','active'),
 ('B-042','Peter John Villanueva',   '3451123497','0551000042','0551000152','Philippines', 2,2,'Transport','Light Van Driver',  '2020-08-21','Lily Villanueva','0551000152','Al Malaz','Riyadh','SAMPLE DATA','active'),
 ('B-043','Gurmehar Singh Sethi',    '3451123498','0551000043','0551000153','India',       3,3,'Transport','Tanker Driver',     '2016-07-14','Jasleen Sethi','0551000153','Al Hamra','Jeddah','SAMPLE DATA','active'),
 ('B-044','Bader Sulaiman Al Zahrani','3451123499','0551000044','0551000154','Saudi Arabia',4,4,'Transport','Bus Driver',        '2019-09-03','Maha Al Zahrani','0551000154','Al Rawdah','Khobar','SAMPLE DATA','active'),
 ('B-045','Dipak Tamang Rai',        '3451123500','0551000045','0551000155','Nepal',       5,1,'Transport','Delivery Driver',   '2021-11-29','Sunita Rai','0551000155','Al Adamah','Dammam','SAMPLE DATA','active'),
 ('B-046','Omar Idris Alenezi',      '3451123501','0551000046','0551000156','Saudi Arabia',1,2,'Transport','Heavy Truck Driver','2015-06-16','Fatimah Alenezi','0551000156','Al Murabaa','Riyadh','SAMPLE DATA','active'),
 ('B-047','Alfredo Santos Bardon',   '3451123502','0551000047','0551000157','Philippines', 2,3,'Transport','Light Van Driver',  '2022-06-02','Nenita Bardon','0551000157','Al Baghdadia','Jeddah','SAMPLE DATA','active'),
 ('B-048','Shakil Ahmed Bhuiyan',    '3451123503','0551000048','0551000158','Bangladesh',  3,4,'Transport','Tanker Driver',     '2018-10-26','Sumaiya Bhuiyan','0551000158','Al Rawabi','Khobar','SAMPLE DATA','active'),
 ('B-049','Rashid Ali Al Mufarrij',  '3451123504','0551000049','0551000159','Saudi Arabia',4,1,'Transport','Bus Driver',        '2017-05-09','Wafa Al Mufarrij','0551000159','Al Qatif Road','Dammam','SAMPLE DATA','active'),
 ('B-050','Navin Raj Poudel',        '3451123505','0551000050','0551000160','Nepal',       5,2,'Transport','Heavy Truck Driver','2023-01-17','Sarita Poudel','0551000160','Al Yasmin','Riyadh','SAMPLE DATA','active');

-- ---------------------------------------------------------------------------
-- Sample passport numbers (passport_number is optional master data).
-- Derived from the driver id so a reseed always produces the same values.
UPDATE drivers
   SET passport_number = CONCAT('P', LPAD(id * 7319, 8, '0'))
 WHERE passport_number IS NULL
   AND remarks LIKE 'SAMPLE DATA%';

