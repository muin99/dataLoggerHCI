#include <Wire.h>
#include <LiquidCrystal_I2C.h>

LiquidCrystal_I2C lcd(0x27, 16, 2);

void setup() {
  Wire.begin(21, 22);
  lcd.init();
  lcd.backlight();

  lcd.setCursor(0, 0);
  lcd.print("Muin");

  lcd.setCursor(0, 1);
  // Total length is 20 characters
  lcd.print("12345678901234567890"); 
}

void loop() {
  // Shift the entire display 1 position to the left
  lcd.scrollDisplayLeft();
  
  // Controls the speed of the scroll (300ms is a smooth walking pace)
  delay(300); 
}
