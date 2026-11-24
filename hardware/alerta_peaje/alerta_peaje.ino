/*
  Dispositivo de alerta del peaje (punto adicional) — Arduino UNO.

  Conexión: Arduino UNO -> cable USB -> adaptador OTG -> celular Android de la caseta (app Peaje IA).
  La app envía 1 byte por serial (9600 baudios) según el proceso:

    'C'  app conectada            -> secuencia de saludo
    'P'  frame enviado / inferencia en curso -> LED amarillo parpadea
    'V'  paso cobrado (válido)    -> LED verde 2 s + pitido corto  ("barrera abierta")
    'A'  ALERTA (no registrada / sin saldo / inactivo) -> LED rojo + buzzer intermitente 3 s
    'N'  frame sin placa legible  -> apaga el amarillo

  Circuito (cada LED con resistencia de 220 Ω a GND):
    D8  LED verde      D9  LED amarillo      D10 LED rojo
    D11 buzzer activo (+) , (-) a GND
    D13 LED integrado: espejo del rojo
*/

const int PIN_VERDE = 8;
const int PIN_AMARILLO = 9;
const int PIN_ROJO = 10;
const int PIN_BUZZER = 11;
const int PIN_INTEGRADO = 13;

unsigned long finVerde = 0;
unsigned long finAlerta = 0;
unsigned long finAmarillo = 0;

void setup() {
  pinMode(PIN_VERDE, OUTPUT);
  pinMode(PIN_AMARILLO, OUTPUT);
  pinMode(PIN_ROJO, OUTPUT);
  pinMode(PIN_BUZZER, OUTPUT);
  pinMode(PIN_INTEGRADO, OUTPUT);
  Serial.begin(9600);
  saludo();
}

void saludo() {
  int pines[] = {PIN_VERDE, PIN_AMARILLO, PIN_ROJO};
  for (int i = 0; i < 3; i++) {
    digitalWrite(pines[i], HIGH);
    delay(150);
    digitalWrite(pines[i], LOW);
  }
}

void pitido(int ms) {
  digitalWrite(PIN_BUZZER, HIGH);
  delay(ms);
  digitalWrite(PIN_BUZZER, LOW);
}

void loop() {
  while (Serial.available() > 0) {
    char c = Serial.read();
    unsigned long ahora = millis();
    switch (c) {
      case 'C':
        saludo();
        pitido(60);
        Serial.println("OK conectado");
        break;
      case 'P':
        finAmarillo = ahora + 600;
        break;
      case 'V':
        finVerde = ahora + 2000;
        finAlerta = 0;
        pitido(80);
        Serial.println("OK cobrado");
        break;
      case 'A':
        finAlerta = ahora + 3000;
        finVerde = 0;
        Serial.println("OK alerta");
        break;
      case 'N':
        finAmarillo = 0;
        break;
    }
  }

  unsigned long ahora = millis();
  digitalWrite(PIN_VERDE, ahora < finVerde ? HIGH : LOW);
  // Amarillo parpadeando mientras hay inferencia en curso.
  digitalWrite(PIN_AMARILLO, (ahora < finAmarillo && (ahora / 100) % 2 == 0) ? HIGH : LOW);
  // Alerta: rojo y buzzer intermitentes (4 Hz).
  bool alerta = ahora < finAlerta;
  bool fase = (ahora / 125) % 2 == 0;
  digitalWrite(PIN_ROJO, alerta ? HIGH : LOW);
  digitalWrite(PIN_INTEGRADO, alerta ? HIGH : LOW);
  digitalWrite(PIN_BUZZER, (alerta && fase) ? HIGH : LOW);
}
