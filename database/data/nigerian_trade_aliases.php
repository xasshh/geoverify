<?php

declare(strict_types=1);

/**
 * The words Nigerian officers and business owners actually use, mapped to ISIC
 * Rev 4 classes.
 *
 * This is a starter list, not a finished one. It exists because an officer typing
 * "mai shayi" or "buka" must land on the right ISIC class without knowing that
 * 5610 is "restaurants and mobile food service activities", and because a picker
 * that only accepts UN wording is a picker that gets the sector wrong six hundred
 * times a week.
 *
 * Expect to tune it against real capture data: the terms officers reach for are
 * a field finding, not a desk one. Weight breaks ties, higher wins.
 *
 * @return list<array{term: string, code: string, weight?: int, language?: string}>
 */
return [
    // Retail, the bulk of any Nigerian commercial street
    ['term' => 'Provisions store', 'code' => '4711', 'weight' => 200],
    ['term' => 'Provision shop', 'code' => '4711', 'weight' => 190],
    ['term' => 'Supermarket', 'code' => '4711', 'weight' => 180],
    ['term' => 'Kiosk', 'code' => '4711', 'weight' => 170],
    ['term' => 'Mini mart', 'code' => '4711', 'weight' => 160],
    ['term' => 'Boutique', 'code' => '4771', 'weight' => 180],
    ['term' => 'Clothing store', 'code' => '4771', 'weight' => 150],
    ['term' => 'Shoe shop', 'code' => '4772', 'weight' => 140],
    ['term' => 'Fabric shop', 'code' => '4751', 'weight' => 150],
    ['term' => 'Ankara and lace dealer', 'code' => '4751', 'weight' => 140],
    ['term' => 'Cosmetics shop', 'code' => '4772', 'weight' => 150],
    ['term' => 'Phone accessories', 'code' => '4741', 'weight' => 180],
    ['term' => 'Recharge card seller', 'code' => '4741', 'weight' => 170],
    ['term' => 'Electronics shop', 'code' => '4742', 'weight' => 160],
    ['term' => 'Building materials', 'code' => '4752', 'weight' => 180],
    ['term' => 'Paint shop', 'code' => '4752', 'weight' => 140],
    ['term' => 'Market stall', 'code' => '4789', 'weight' => 190],
    ['term' => 'Street trader', 'code' => '4789', 'weight' => 170],
    ['term' => 'Hawker', 'code' => '4789', 'weight' => 150],

    // Food and drink
    ['term' => 'Buka', 'code' => '5610', 'weight' => 220],
    ['term' => 'Bukateria', 'code' => '5610', 'weight' => 210],
    ['term' => 'Mama put', 'code' => '5610', 'weight' => 210],
    ['term' => 'Mai shayi', 'code' => '5610', 'weight' => 200, 'language' => 'ha'],
    ['term' => 'Tea seller', 'code' => '5610', 'weight' => 190],
    ['term' => 'Restaurant', 'code' => '5610', 'weight' => 200],
    ['term' => 'Suya spot', 'code' => '5610', 'weight' => 200],
    ['term' => 'Beer parlour', 'code' => '5630', 'weight' => 200],
    ['term' => 'Bar', 'code' => '5630', 'weight' => 180],
    ['term' => 'Bakery', 'code' => '1071', 'weight' => 190],
    ['term' => 'Cold room', 'code' => '4721', 'weight' => 190],
    ['term' => 'Frozen foods', 'code' => '4721', 'weight' => 180],
    ['term' => 'Butcher', 'code' => '4721', 'weight' => 170],
    ['term' => 'Fish seller', 'code' => '4721', 'weight' => 170],
    ['term' => 'Sachet water factory', 'code' => '1104', 'weight' => 190],
    ['term' => 'Pure water factory', 'code' => '1104', 'weight' => 190],

    // Money
    ['term' => 'POS operator', 'code' => '6619', 'weight' => 250],
    ['term' => 'POS agent', 'code' => '6619', 'weight' => 240],
    ['term' => 'Mobile money agent', 'code' => '6619', 'weight' => 220],
    ['term' => 'Bureau de change', 'code' => '6619', 'weight' => 190],
    ['term' => 'Microfinance bank', 'code' => '6419', 'weight' => 190],
    ['term' => 'Bank branch', 'code' => '6419', 'weight' => 180],

    // Motor and repair
    ['term' => 'Vulcanizer', 'code' => '4520', 'weight' => 230],
    ['term' => 'Mechanic', 'code' => '4520', 'weight' => 220],
    ['term' => 'Auto repair', 'code' => '4520', 'weight' => 200],
    ['term' => 'Car wash', 'code' => '4520', 'weight' => 190],
    ['term' => 'Panel beater', 'code' => '4520', 'weight' => 190],
    ['term' => 'Okada repair', 'code' => '4540', 'weight' => 190],
    ['term' => 'Keke repair', 'code' => '4540', 'weight' => 190],
    ['term' => 'Filling station', 'code' => '4730', 'weight' => 210],
    ['term' => 'Petrol station', 'code' => '4730', 'weight' => 210],
    ['term' => 'Cooking gas plant', 'code' => '4730', 'weight' => 190],
    ['term' => 'Generator repair', 'code' => '3312', 'weight' => 180],
    ['term' => 'Phone repair', 'code' => '9512', 'weight' => 200],
    ['term' => 'Computer repair', 'code' => '9511', 'weight' => 180],

    // Trades and making
    ['term' => 'Tailor', 'code' => '1410', 'weight' => 220],
    ['term' => 'Fashion designer', 'code' => '1410', 'weight' => 200],
    ['term' => 'Carpenter', 'code' => '3100', 'weight' => 200],
    ['term' => 'Furniture maker', 'code' => '3100', 'weight' => 190],
    ['term' => 'Welder', 'code' => '2599', 'weight' => 200],
    ['term' => 'Iron bender', 'code' => '2599', 'weight' => 180],
    ['term' => 'Block industry', 'code' => '2395', 'weight' => 200],
    ['term' => 'Block moulding', 'code' => '2395', 'weight' => 190],
    ['term' => 'Printing press', 'code' => '1811', 'weight' => 190],
    ['term' => 'Plumber', 'code' => '4322', 'weight' => 180],
    ['term' => 'Electrician', 'code' => '4321', 'weight' => 180],

    // Services
    ['term' => 'Barbing salon', 'code' => '9602', 'weight' => 230],
    ['term' => 'Barber', 'code' => '9602', 'weight' => 220],
    ['term' => 'Hair salon', 'code' => '9602', 'weight' => 220],
    ['term' => 'Saloon', 'code' => '9602', 'weight' => 200],
    ['term' => 'Laundry', 'code' => '9601', 'weight' => 190],
    ['term' => 'Dry cleaner', 'code' => '9601', 'weight' => 180],
    ['term' => 'Business centre', 'code' => '8219', 'weight' => 210],
    ['term' => 'Cyber cafe', 'code' => '6312', 'weight' => 180],
    ['term' => 'Photo studio', 'code' => '7420', 'weight' => 180],
    ['term' => 'Estate agent', 'code' => '6820', 'weight' => 170],
    ['term' => 'Law chambers', 'code' => '6910', 'weight' => 170],
    ['term' => 'Accountant', 'code' => '6920', 'weight' => 160],
    ['term' => 'Haulage', 'code' => '4923', 'weight' => 170],
    ['term' => 'Transport company', 'code' => '4923', 'weight' => 170],

    // Health, education, worship
    ['term' => 'Chemist', 'code' => '4772', 'weight' => 230],
    ['term' => 'Patent medicine store', 'code' => '4772', 'weight' => 220],
    ['term' => 'Pharmacy', 'code' => '4772', 'weight' => 210],
    ['term' => 'Clinic', 'code' => '8620', 'weight' => 200],
    ['term' => 'Maternity home', 'code' => '8610', 'weight' => 190],
    ['term' => 'Hospital', 'code' => '8610', 'weight' => 200],
    ['term' => 'Laboratory', 'code' => '8690', 'weight' => 170],
    ['term' => 'Nursery and primary school', 'code' => '8510', 'weight' => 210],
    ['term' => 'Secondary school', 'code' => '8530', 'weight' => 190],
    ['term' => 'Church', 'code' => '9491', 'weight' => 200],
    ['term' => 'Mosque', 'code' => '9491', 'weight' => 200],

    // Lodging and farming
    ['term' => 'Hotel', 'code' => '5510', 'weight' => 200],
    ['term' => 'Guest house', 'code' => '5510', 'weight' => 190],
    ['term' => 'Poultry farm', 'code' => '0146', 'weight' => 190],
    ['term' => 'Agro chemicals', 'code' => '4773', 'weight' => 170],
    ['term' => 'Farm inputs', 'code' => '4773', 'weight' => 160],
];
