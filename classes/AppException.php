<?php
class AppException extends RuntimeException {
}
class ValidationException extends AppException {
}
class AccessDeniedException extends AppException {
}
class EntityNotFoundException extends AppException {
}
class ConflictException extends AppException {
}
class FileStorageException extends AppException {
}
class AuthenticationException extends AppException {
}
class NotificationException extends AppException {
}
class ConfigurationException extends AppException {
}
class PersistenceException extends AppException {
}
class IntegrationException extends AppException {
}
