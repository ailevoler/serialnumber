-- XGEN LIFE Intl v4: use QR Ph as the only PayMongo payment method.
-- To offer other methods later, tick them in Admin → PayMongo Settings.
UPDATE settings SET svalue = 'qrph' WHERE skey = 'paymongo_methods';
