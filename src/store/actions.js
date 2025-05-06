export const pushNotification = ( id, message ) => ( {
	type: 'PUSH_NOTIFICATION',
	id,
	message,
} );

export const dismissNotification = ( id ) => ( {
	type: 'DISMISS_NOTIFICATION',
	id,
} );
