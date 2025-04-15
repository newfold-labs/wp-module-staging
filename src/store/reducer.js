const DEFAULT_STATE = {
	isProduction: false,
	productionUrl: null,
	hasStaging: false,
	feed: {},
};

export default function reducer( state = DEFAULT_STATE, action ) {
	switch ( action.type ) {
		/*case 'SET_CACHE_LEVEL':
			return {
				...state,
				cacheLevel: action.level,
			};*/

		case 'PUSH_NOTIFICATION':
			return {
				...state,
				feed: {
					...state.feed,
					[ action.id ]: action.message,
				},
			};

		case 'DISMISS_NOTIFICATION':
			return {
				...state,
				feed: {
					...state.feed,
					[ action.id ]: null,
				},
			};

		default:
			return state;
	}
}
