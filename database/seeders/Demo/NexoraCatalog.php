<?php

namespace Database\Seeders\Demo;

/**
 * Static reference data for the Nexora Group demo organization.
 * All companies, people and products are fictional; prices are in PKR.
 */
final class NexoraCatalog
{
    /** code => [name, city, opened_on, relative order volume] */
    public const BRANCHES = [
        'KHI' => ['Karachi — Clifton', 'Karachi', '2017-03-01', 1.25],
        'LHR' => ['Lahore — Gulberg', 'Lahore', '2019-08-15', 1.00],
        'ISB' => ['Islamabad — Blue Area', 'Islamabad', '2021-11-01', 0.70],
    ];

    /** code => name */
    public const DEPARTMENTS = [
        'SAL' => 'Sales',
        'OPS' => 'Operations',
        'WHS' => 'Warehouse & Logistics',
        'FIN' => 'Finance',
        'MKT' => 'Marketing',
        'HRA' => 'HR & Administration',
    ];

    /**
     * category => [sku prefix, gross margin, popularity weight, products [name, price]]
     *
     * @var array<string, array{0: string, 1: float, 2: int, 3: list<array{0: string, 1: int}>}>
     */
    public const PRODUCTS = [
        'Consumer Electronics' => ['CE', 0.18, 14, [
            ['LED TV 32" HD', 38500], ['LED TV 43" Full HD', 72000], ['Smart TV 55" 4K', 154000], ['Bluetooth speaker 20W', 6900],
            ['Soundbar 2.1 channel', 24500], ['Over-ear headphones', 8900], ['Wireless earbuds', 5400], ['Smartwatch fitness', 9800],
            ['Power bank 20,000 mAh', 4600], ['Android tablet 10"', 46500], ['Streaming stick 4K', 11900], ['Wi-Fi 6 router', 13800],
            ['HD webcam 1080p', 6200], ['Smart plug (2-pack)', 3900], ['Action camera 4K', 27500],
        ]],
        'Home Appliances' => ['HA', 0.24, 16, [
            ['Air fryer 5L', 18500], ['Microwave oven 25L', 31500], ['Steam iron 2200W', 6200], ['Electric kettle 1.7L', 4300],
            ['Blender 1.5L', 8700], ['Juicer machine', 11200], ['Bagless vacuum cleaner', 22800], ['Water dispenser', 34500],
            ['Gas room heater', 16900], ['Pedestal fan 24"', 13400], ['Ceiling fan 56" inverter', 17800], ['Twin-tub washing machine', 49500],
            ['Rice cooker 1.8L', 7600], ['Sandwich maker', 4900], ['Air cooler 60L', 39800],
        ]],
        'Mobile Accessories' => ['MA', 0.46, 28, [
            ['USB-C cable 1m', 950], ['Lightning cable 1m', 1350], ['Fast charger 25W', 2450], ['Car charger dual port', 1850],
            ['Silicone phone case', 1150], ['Tempered glass protector', 650], ['Magnetic car mount', 1750], ['Wireless charging pad', 3200],
            ['Selfie stick tripod', 2300], ['Phone ring holder', 450], ['Micro SD card 64GB', 2150], ['OTG adapter', 550],
            ['Earphones with mic', 1250], ['Laptop sleeve 15"', 2900], ['Multi-port USB hub', 3400],
        ]],
        'Kitchenware' => ['KW', 0.34, 12, [
            ['Non-stick cookware set (7 pc)', 16800], ['Pressure cooker 7L', 8900], ['Knife set with block', 5400], ['Dinner set 72 pc', 23500],
            ['Glass storage containers (10)', 4200], ['Cast iron karahi', 6300], ['Steel water bottle 1L', 1650], ['Tea set porcelain', 7800],
            ['Chopping board set', 2100], ['Spice rack 16 jars', 3600], ['Lunch box insulated', 2400], ['Baking tray set', 3900],
            ['Thermos flask 1.5L', 3300], ['Kitchen scale digital', 2700], ['Wok 32cm', 5900],
        ]],
        'Personal Care' => ['PC', 0.38, 13, [
            ['Beard trimmer', 4800], ['Hair dryer 2000W', 5900], ['Hair straightener', 6400], ['Electric toothbrush', 7200],
            ['Digital bathroom scale', 3800], ['Grooming kit 11-in-1', 8900], ['Facial steamer', 4600], ['Massage gun', 14500],
            ['Epilator', 9800], ['Hair clipper pro', 6900], ['Curling iron', 5200], ['Nail care set', 1900],
            ['Blood pressure monitor', 7900], ['Infrared thermometer', 3400], ['Foot spa', 8200],
        ]],
        'Office Supplies' => ['OS', 0.29, 10, [
            ['A4 paper ream 80gsm', 1650], ['Printer ink black', 3900], ['Printer ink colour', 5200], ['Heavy-duty stapler', 1450],
            ['Whiteboard 4x3 ft', 7600], ['Desk organizer', 1950], ['Laser printer mono', 48500], ['Paper shredder', 18900],
            ['Ergonomic mouse', 3200], ['Wireless keyboard', 4400], ['Document scanner', 36500], ['File cabinet 4-drawer', 29800],
            ['Label maker', 8900], ['Box files (10)', 2800], ['Calculator scientific', 2600],
        ]],
        'Furniture' => ['FN', 0.31, 5, [
            ['Ergonomic office chair', 38500], ['Study table with drawer', 21500], ['Bookshelf 5-tier', 17800], ['3-seater sofa', 118000],
            ['Coffee table', 19500], ['Shoe rack', 8900], ['TV console', 27900], ['Dining table 6-seater', 96000],
            ['Bedside table', 9800], ['Wardrobe 3-door', 74500], ['Computer desk L-shape', 33900], ['Bar stool (pair)', 15600],
            ['Recliner chair', 58000], ['Kids study chair', 7900], ['Folding table', 11200],
        ]],
        'Lighting' => ['LT', 0.42, 9, [
            ['LED bulb 12W', 450], ['LED tube light 20W', 950], ['Rechargeable emergency light', 3400], ['Solar garden lamp', 2900],
            ['Desk lamp LED', 3600], ['Ceiling panel light 24W', 2700], ['Smart bulb RGB', 2300], ['LED strip 5m', 2100],
            ['Floodlight 50W', 5400], ['Chandelier modern', 28500], ['Wall sconce', 4800], ['Motion sensor light', 1900],
            ['Torch rechargeable', 1650], ['Night lamp', 850], ['Solar street light 100W', 18900],
        ]],
    ];

    /** Fictional names (generic combinations; not real individuals). */
    public const FIRST_NAMES = [
        'Ahmed', 'Ayesha', 'Bilal', 'Fatima', 'Hamza', 'Hira', 'Usman', 'Zainab', 'Ali', 'Maryam', 'Saad', 'Sana', 'Imran', 'Nida',
        'Faisal', 'Amna', 'Kamran', 'Rabia', 'Danish', 'Mehwish', 'Junaid', 'Iqra', 'Adnan', 'Komal', 'Waqas', 'Sadia', 'Asad', 'Mahnoor',
        'Fahad', 'Areeba', 'Shahzaib', 'Laiba', 'Tariq', 'Noor', 'Haris', 'Anum', 'Owais', 'Kiran', 'Yasir', 'Hafsa',
    ];

    public const LAST_NAMES = [
        'Khan', 'Malik', 'Qureshi', 'Siddiqui', 'Butt', 'Chaudhry', 'Sheikh', 'Raza', 'Hussain', 'Iqbal', 'Mirza', 'Abbasi',
        'Hashmi', 'Javed', 'Aslam', 'Farooq', 'Rehman', 'Shah', 'Akhtar', 'Baig', 'Zafar', 'Anwar', 'Rana', 'Nawaz',
    ];

    /**
     * Staffing plan: [department, branch code|null (head office), job title, count, salary range].
     *
     * @var list<array{0: string, 1: string|null, 2: string, 3: int, 4: array{0: int, 1: int}}>
     */
    public const STAFFING = [
        ['SAL', 'KHI', 'Store Manager', 1, [260000, 320000]], ['SAL', 'KHI', 'Sales Associate', 10, [62000, 92000]], ['OPS', 'KHI', 'Cashier', 3, [58000, 72000]],
        ['WHS', 'KHI', 'Warehouse Supervisor', 1, [140000, 170000]], ['WHS', 'KHI', 'Logistics Associate', 5, [55000, 70000]],
        ['SAL', 'LHR', 'Store Manager', 1, [240000, 300000]], ['SAL', 'LHR', 'Sales Associate', 8, [60000, 88000]], ['OPS', 'LHR', 'Cashier', 3, [56000, 70000]],
        ['WHS', 'LHR', 'Warehouse Supervisor', 1, [135000, 165000]], ['WHS', 'LHR', 'Logistics Associate', 4, [54000, 68000]],
        ['SAL', 'ISB', 'Store Manager', 1, [230000, 290000]], ['SAL', 'ISB', 'Sales Associate', 6, [60000, 86000]], ['OPS', 'ISB', 'Cashier', 2, [56000, 70000]],
        ['WHS', 'ISB', 'Logistics Associate', 3, [54000, 68000]],
        ['FIN', null, 'Finance Manager', 1, [380000, 450000]], ['FIN', null, 'Accountant', 3, [120000, 165000]],
        ['MKT', null, 'Marketing Lead', 1, [320000, 380000]], ['MKT', null, 'Digital Marketing Executive', 3, [95000, 140000]],
        ['HRA', null, 'HR Manager', 1, [300000, 360000]], ['HRA', null, 'Admin Officer', 2, [85000, 110000]],
        ['OPS', null, 'Operations Manager', 1, [360000, 420000]], ['OPS', null, 'IT Support Engineer', 2, [130000, 175000]],
    ];
}
