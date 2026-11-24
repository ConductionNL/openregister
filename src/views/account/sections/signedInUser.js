import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

/**
 * Ask the API who is signed in, with what the auth backend lets them change.
 *
 * @return {Promise<object>} The response; its data holds the user
 */
export function fetchSignedInUser() {
	return axios.get(generateUrl('/apps/openregister/api/user/me'))
}
