import axios from 'axios';

/**
 * Separate from services/api.js deliberately — that instance attaches a
 * bearer token and reacts to 401s, neither of which applies to guest
 * checkout (no account, no token, ever). Same baseURL, so requests still
 * reach the same backend.
 */
const guestApi = axios.create({
  baseURL: import.meta.env.VITE_API_BASE_URL || '/api/v1',
  headers: {
    Accept: 'application/json',
  },
});

export default guestApi;
