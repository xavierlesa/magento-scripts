INSERT INTO 
    directory_country_region (country_id , code, default_name)
VALUES
    ('AR', 'BA', 'Buenos Aires'),
    ('AR', 'SA', 'Salta'),
    ('AR', 'CA', 'Catamarca'),
    ('AR', 'CH', 'Chaco'),
    ('AR', 'UT', 'Chubut'),
    ('AR', 'CO', 'Cordoba'),
    ('AR', 'CT', 'Corrientes'),
    ('AR', 'ER', 'Entre Rios'),
    ('AR', 'FO', 'Formosa'),
    ('AR', 'JU', 'Jujuy'),
    ('AR', 'LP', 'La Pampa'),
    ('AR', 'LR', 'La Rioja'),
    ('AR', 'ME', 'Mendoza'),
    ('AR', 'MI', 'Misiones'),
    ('AR', 'NE', 'Neuquen'),
    ('AR', 'RO', 'Rio Negro'),
    ('AR', 'SJ', 'San Juan'),
    ('AR', 'SL', 'San Luis'),
    ('AR', 'SF', 'Santa Fe'),
    ('AR', 'SE', 'Santiago del Estero'),
    ('AR', 'TR', 'Tierra del Fuego'),
    ('AR', 'TU', 'Tucuman'),
    
    -- Zonas GBA
    ('AR', 'CABA', 'Capital Federal'),
    ('AR', 'GBAN', 'GBA Norte'),
    ('AR', 'GBAS', 'GBA Sur'),
    ('AR', 'GBAO', 'GBA Oeste');

INSERT INTO
    directory_country_region_name (locale, region_id , name)
SELECT 'en_US' as locale, region_id, default_name 
FROM directory_country_region 
WHERE country_id = 'AR';
