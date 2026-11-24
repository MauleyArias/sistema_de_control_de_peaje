/// Configuración en tiempo de compilación:
/// flutter build apk --release --dart-define=API_URL=https://peaje-ia.onrender.com --dart-define=GOOGLE_WEB_CLIENT_ID=xxxx.apps.googleusercontent.com
class Config {
  static const apiUrl = String.fromEnvironment('API_URL', defaultValue: 'https://peaje-ia.onrender.com');

  /// ID de cliente OAuth de tipo "Aplicación web" (serverClientId): el ID token sale con aud = este ID
  /// y el backend lo valida. El cliente "Android" (paquete + SHA-1) también debe existir en Google Cloud.
  static const googleWebClientId = String.fromEnvironment(
    'GOOGLE_WEB_CLIENT_ID',
    defaultValue: '380938779604-fkcj32873ivu6k15jnqnukq9v1lvo478.apps.googleusercontent.com',
  );

  /// Frames por segundo enviados en modo Caseta (la consigna pide 2 a 5).
  static const fps = int.fromEnvironment('FPS', defaultValue: 2);
}
